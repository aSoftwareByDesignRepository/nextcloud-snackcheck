<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Consumption logging with idempotency, period lock, and attribution rules.
 * Never auto-decrements on_hand.
 */
class ConsumptionLogService
{
	public const IDEMPOTENCY_TTL_HOURS = 24;
	public const UNDO_SECONDS = 60;
	public const REASON_MAX_LEN = 500;

	public function __construct(
		private readonly ConsumptionLogMapper $mapper,
		private readonly PeriodMapper $periodMapper,
		private readonly CatalogService $catalog,
		private readonly PeriodService $periods,
		private readonly SettingsService $settings,
		private readonly AuditService $audit,
		private readonly \OCA\SnackCheck\Db\HospAllowMapper $hospAllow,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * @param array{
	 *   itemId:int, qty:int, idempotencyKey:string, siteId:int,
	 *   actorUserId:string, source:string, mode?:string,
	 *   targetUserId?:string|null, proxyReason?:string|null,
	 *   hospitalityReason?:string|null, deviceId?:string|null,
	 *   isKitchenAdmin?:bool
	 * } $input
	 * @return array{log: ConsumptionLog, replay: bool, httpStatus: int}
	 */
	public function create(array $input): array
	{
		$itemId = (int)$input['itemId'];
		$qty = (int)$input['qty'];
		$key = trim((string)$input['idempotencyKey']);
		$siteId = (int)$input['siteId'];
		$actor = (string)$input['actorUserId'];
		$source = (string)$input['source'];
		$mode = (string)($input['mode'] ?? 'self');
		$deviceId = $input['deviceId'] ?? null;

		if ($key === '' || mb_strlen($key) > 128) {
			throw new DomainException('validation_failed', 'Idempotency key required', 422);
		}
		if ($qty < 1 || $qty > 100) {
			throw new DomainException('qty_invalid', 'Invalid qty', 422);
		}

		$existing = $this->mapper->findByIdempotencyKey($key);
		if ($existing !== null) {
			$bodyHash = $this->requestFingerprint($input);
			$stored = $this->storedFingerprint($existing);
			if (!hash_equals($bodyHash, $stored)) {
				throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
			}
			$age = $this->timeFactory->getTime() - ($existing->getCreatedAt()?->getTimestamp() ?? 0);
			if ($age > self::IDEMPOTENCY_TTL_HOURS * 3600) {
				// Outside 24h window: treat as conflict rather than silent new row with same key
				throw new DomainException('idempotency_conflict', 'Idempotency key expired', 409);
			}
			return ['log' => $existing, 'replay' => true, 'httpStatus' => 200];
		}

		$this->db->beginTransaction();
		try {
			$period = $this->periods->getOpenOrFail();
			$locked = $this->periodMapper->lockRow((int)$period->getId());
			if ($locked === null || $locked->getState() !== 'open') {
				throw new DomainException('period_closed', 'Period closed', 409);
			}

			// Re-check idempotency inside transaction — still enforce fingerprint (race safety).
			$existing2 = $this->mapper->findByIdempotencyKey($key);
			if ($existing2 !== null) {
				$bodyHash = $this->requestFingerprint($input);
				$stored = $this->storedFingerprint($existing2);
				if (!hash_equals($bodyHash, $stored)) {
					throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
				}
				$this->db->commit();
				return ['log' => $existing2, 'replay' => true, 'httpStatus' => 200];
			}

			// Aristoteles MF: item + attribution under the same period lock (closes deactivate/hosp TOCTOU).
			// Lock order: period row → catalog row (never reverse — avoids deadlock with close/create).
			$item = $this->catalog->getForUpdate($itemId);
			if ((int)$item->getActive() !== 1) {
				throw new DomainException('item_inactive', 'Item inactive', 422);
			}
			if ((int)$item->getSiteId() !== $siteId) {
				throw new DomainException('permission_denied', 'Site mismatch', 403);
			}
			[$userId, $loggedBy, $billingBucket, $proxyReason, $hospReason, $finalSource, $action] =
				$this->resolveAttribution($mode, $actor, $input, $source);
			$unit = (int)$item->getPriceCents();
			$lineTotal = SubsidyService::lineTotalCents($qty, $unit);
			$display = $this->displayName($userId);

			$log = new ConsumptionLog();
			$log->setPeriodId((int)$locked->getId());
			$log->setSiteId($siteId);
			$log->setUserId($userId);
			$log->setUserDisplaySnap($display);
			$log->setItemId($itemId);
			$log->setItemNameSnap($item->getName());
			$log->setQty($qty);
			$log->setUnitPriceCents($unit);
			$log->setLineTotalCents($lineTotal);
			$log->setBillingBucket($billingBucket);
			$log->setSource($finalSource);
			$log->setDeviceId($deviceId !== null ? (string)$deviceId : null);
			$log->setLoggedBy($loggedBy);
			$log->setProxyReason($proxyReason);
			$log->setHospReason($hospReason);
			$log->setIdempotencyKey($key);
			$log->setCreatedAt($this->timeFactory->getDateTime());
			$log = $this->mapper->insert($log);
			// MH-08: audit in the same transaction as the ledger row.
			$this->audit->record($actor, $action, 'consumption_log', (string)$log->getId(), [
				'item_id' => $itemId,
				'qty' => $qty,
				'line_total_cents' => $lineTotal,
				'billing_bucket' => $billingBucket,
			]);
			$this->db->commit();
		} catch (DomainException $e) {
			$this->db->rollBack();
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// Unique constraint race → replay only when fingerprint matches
			$again = $this->mapper->findByIdempotencyKey($key);
			if ($again !== null) {
				$bodyHash = $this->requestFingerprint($input);
				$stored = $this->storedFingerprint($again);
				if (!hash_equals($bodyHash, $stored)) {
					throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
				}
				return ['log' => $again, 'replay' => true, 'httpStatus' => 200];
			}
			throw $e;
		}

		// NN-01: intentionally do NOT touch on_hand

		return ['log' => $log, 'replay' => false, 'httpStatus' => 201];
	}

	public function void(
		int $logId,
		string $actorUid,
		string $reason,
		bool $isAdmin,
		bool $allowLoggedByActor = false,
		bool $enforceSelfUndoWindow = false,
		?int $requiredSiteId = null,
	): ConsumptionLog {
		$reason = trim($reason);
		if (mb_strlen($reason) < 3) {
			throw new DomainException('validation_failed', 'Void reason required', 422);
		}
		if (mb_strlen($reason) > self::REASON_MAX_LEN) {
			throw new DomainException('validation_failed', 'Void reason too long', 422);
		}
		$this->db->beginTransaction();
		try {
			// Row lock first — concurrent void/undo must serialize on the same log.
			$log = $this->mapper->lockRow($logId);
			if ($log === null) {
				throw new DomainException('not_found', 'Log not found', 404);
			}
			if ($log->getVoidedAt() !== null) {
				$this->db->commit();
				return $log;
			}
			$period = $this->periodMapper->lockRow((int)$log->getPeriodId());
			if ($period === null || $period->getState() !== 'open') {
				throw new DomainException('period_closed', 'Cannot void in closed period', 409);
			}
			if ($enforceSelfUndoWindow) {
				// Permission + undo TTL under the same row lock (closes TOCTOU on window expiry).
				if ($log->getBillingBucket() === 'company_hospitality') {
					if ((string)$log->getLoggedBy() !== $actorUid) {
						throw new DomainException('permission_denied', 'Not your hospitality log', 403);
					}
				} elseif ($log->getUserId() !== $actorUid && (string)$log->getLoggedBy() !== $actorUid) {
					throw new DomainException('permission_denied', 'Not your log', 403);
				}
				$created = $log->getCreatedAt()?->getTimestamp() ?? 0;
				if ($this->timeFactory->getTime() - $created > self::UNDO_SECONDS) {
					throw new DomainException('validation_failed', 'Undo window expired', 422);
				}
				// Argus MF: tablet undo must not cross kitchens — bind under the same lock.
				if ($requiredSiteId !== null && (int)$log->getSiteId() !== $requiredSiteId) {
					throw new DomainException('foreign_site', 'Log is not for this site', 403);
				}
			} elseif ($isAdmin) {
				// Zeus MF: site ACL under the same FOR UPDATE as void — never trust a pre-lock find().
				if (!$this->access->canManageSite($actorUid, (int)$log->getSiteId())) {
					throw new DomainException('foreign_site', 'Site not allowed for this manager', 403);
				}
			} else {
				$ok = $log->getUserId() === $actorUid
					|| ($allowLoggedByActor && (string)$log->getLoggedBy() === $actorUid);
				if (!$ok) {
					throw new DomainException('permission_denied', 'Not your log', 403);
				}
			}
			$log->setVoidedAt($this->timeFactory->getDateTime());
			$log->setVoidedBy($actorUid);
			$log->setVoidReason($reason);
			$log = $this->mapper->update($log);
			$this->audit->record($actorUid, 'log.void', 'consumption_log', (string)$logId, ['reason' => $reason]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $log;
	}

	public function selfUndo(int $logId, string $actorUid, ?int $requiredSiteId = null): ConsumptionLog
	{
		// All auth + TTL (+ optional site bind) checks run under lockRow inside void.
		return $this->void($logId, $actorUid, 'self-undo', false, true, true, $requiredSiteId);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{0:string,1:?string,2:string,3:?string,4:?string,5:string,6:string}
	 */
	private function resolveAttribution(string $mode, string $actor, array $input, string $source): array
	{
		if ($mode === 'hospitality') {
			if (!$this->settings->isHospitalityEnabled()) {
				throw new DomainException('hospitality_disabled', 'Hospitality disabled', 403);
			}
			if (!$this->hospAllow->isAllowed($actor)) {
				throw new DomainException('permission_denied', 'Not on hospitality allowlist', 403);
			}
			$company = $this->settings->getHospitalityCompanyUserId();
			if ($company === '') {
				throw new DomainException('hospitality_disabled', 'Company user missing', 422);
			}
			if ($this->userManager->get($company) === null) {
				throw new DomainException('hospitality_disabled', 'Company user missing', 422);
			}
			$reason = trim((string)($input['hospitalityReason'] ?? ''));
			if (mb_strlen($reason) < 3) {
				throw new DomainException('validation_failed', 'Hospitality reason required', 422);
			}
			if (mb_strlen($reason) > self::REASON_MAX_LEN) {
				throw new DomainException('validation_failed', 'Hospitality reason too long', 422);
			}
			$src = str_starts_with($source, 'hospitality') ? $source : (
				$source === 'terminal' ? 'hospitality_terminal' : 'hospitality_web'
			);
			return [$company, $actor, 'company_hospitality', null, $reason, $src, 'log.hospitality_create'];
		}

		if ($mode === 'proxy') {
			if (empty($input['isKitchenAdmin'])) {
				throw new DomainException('permission_denied', 'Proxy requires kitchen admin', 403);
			}
			$target = trim((string)($input['targetUserId'] ?? ''));
			$reason = trim((string)($input['proxyReason'] ?? ''));
			if ($target === '') {
				throw new DomainException('validation_failed', 'targetUserId required', 422);
			}
			if ($this->userManager->get($target) === null) {
				throw new DomainException('validation_failed', 'Unknown user', 422);
			}
			if (mb_strlen($reason) < 3) {
				throw new DomainException('proxy_reason_required', 'Proxy reason required', 422);
			}
			if (mb_strlen($reason) > self::REASON_MAX_LEN) {
				throw new DomainException('validation_failed', 'Proxy reason too long', 422);
			}
			// US-008: proxy target must pass the SnackCheck access door.
			if (!$this->access->canAccessApp($target)) {
				throw new DomainException('permission_denied', 'Target cannot access SnackCheck', 403);
			}
			return [$target, $actor, 'personal', $reason, null, 'admin_proxy', 'log.proxy_create'];
		}

		// self — never trust client userId
		return [$actor, $actor, 'personal', null, null, $source, 'log.create'];
	}

	/**
	 * Chargeable personal total for unlocked user in open period (kiosk quick-total strip).
	 * Org-wide across all sites — matches My month / payroll deduct basis (MH-23 / Y13).
	 * $siteId retained for call-site BC; favorites stay site-scoped separately.
	 */
	public function quickTotalCentsForUser(string $userId, int $siteId = 0): int
	{
		try {
			$period = $this->periods->getOpenOrFail();
		} catch (\Throwable) {
			return 0;
		}
		$total = 0;
		foreach ($this->mapper->findForUserPeriod((int)$period->getId(), $userId) as $log) {
			if ($log->getVoidedAt() !== null) {
				continue;
			}
			if ($log->getBillingBucket() !== 'personal') {
				continue;
			}
			// Intentionally ignore $siteId — strip must match org-wide My month.
			$line = (int)$log->getLineTotalCents();
			if ($line > 0) {
				$total += $line;
			}
		}
		return $total;
	}

	/** @return list<int> last distinct item ids (max 5) for favorites strip */
	public function lastItemIdsForUser(string $userId, int $siteId, int $limit = 5): array
	{
		try {
			$period = $this->periods->getOpenOrFail();
		} catch (\Throwable) {
			return [];
		}
		$ids = [];
		foreach ($this->mapper->findForUserPeriod((int)$period->getId(), $userId) as $log) {
			if ($siteId > 0 && (int)$log->getSiteId() !== $siteId) {
				continue;
			}
			$itemId = (int)($log->getItemId() ?? 0);
			if ($itemId <= 0 || isset($ids[$itemId])) {
				continue;
			}
			$ids[$itemId] = true;
			if (count($ids) >= $limit) {
				break;
			}
		}
		return array_map('intval', array_keys($ids));
	}

	/** @param array<string,mixed> $input */
	private function requestFingerprint(array $input): string
	{
		$canon = [
			'itemId' => (int)$input['itemId'],
			'qty' => (int)$input['qty'],
			'siteId' => (int)$input['siteId'],
			'actorUserId' => (string)($input['actorUserId'] ?? ''),
			'mode' => (string)($input['mode'] ?? 'self'),
			'targetUserId' => (string)($input['targetUserId'] ?? ''),
			'proxyReason' => (string)($input['proxyReason'] ?? ''),
			'hospitalityReason' => (string)($input['hospitalityReason'] ?? ''),
		];
		return hash('sha256', json_encode($canon, JSON_THROW_ON_ERROR));
	}

	private function storedFingerprint(ConsumptionLog $log): string
	{
		$mode = 'self';
		if ($log->getBillingBucket() === 'company_hospitality') {
			$mode = 'hospitality';
		} elseif ($log->getSource() === 'admin_proxy') {
			$mode = 'proxy';
		}
		$actor = (string)($log->getLoggedBy() ?? $log->getUserId());
		$canon = [
			'itemId' => (int)($log->getItemId() ?? 0),
			'qty' => (int)$log->getQty(),
			'siteId' => (int)$log->getSiteId(),
			'actorUserId' => $actor,
			'mode' => $mode,
			'targetUserId' => $mode === 'proxy' ? (string)$log->getUserId() : '',
			'proxyReason' => (string)($log->getProxyReason() ?? ''),
			'hospitalityReason' => (string)($log->getHospReason() ?? ''),
		];
		return hash('sha256', json_encode($canon, JSON_THROW_ON_ERROR));
	}

	private function displayName(string $userId): string
	{
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return $userId;
		}
		$dn = $user->getDisplayName();
		return $dn !== '' ? $dn : $userId;
	}
}
