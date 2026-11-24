<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
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
	public const EXPENSE_MAX_CENTS = 99_999_999;
	public const EXPENSE_ITEM_SNAP = 'Expense';

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
		private readonly ?ExpenseClaimsBridge $claimsBridge = null,
		private readonly ?\OCP\Notification\IManager $notificationManager = null,
		private readonly ?\OCP\IURLGenerator $urlGenerator = null,
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
				// Uniform 404 — an itemId from another kitchen must be indistinguishable
				// from a missing item (no existence oracle across sites).
				throw new DomainException('not_found', 'Item not found', 404);
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
			// snk_logs_idem_uq race: the winner committed while we were in-flight →
			// replay only when the fingerprint matches (unique_index_write_no_conflict_path).
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$again = $this->mapper->findByIdempotencyKey($key);
				if ($again !== null) {
					$bodyHash = $this->requestFingerprint($input);
					$stored = $this->storedFingerprint($again);
					if (!hash_equals($bodyHash, $stored)) {
						throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
					}
					return ['log' => $again, 'replay' => true, 'httpStatus' => 200];
				}
			}
			throw $e;
		}

		// NN-01: intentionally do NOT touch on_hand

		return ['log' => $log, 'replay' => false, 'httpStatus' => 201];
	}

	/**
	 * Expense credit ("Auslage") — a negative ledger entry in bucket
	 * personal_credit (NN-10: line_total MUST stay < 0; qty pinned to 1;
	 * item NULL; name snapshot stays the canonical, never-localized label).
	 *
	 * @param array{
	 *   amountCents:int, expenseReason:string, idempotencyKey:string, siteId:int,
	 *   actorUserId:string, source:string, targetUserId?:string|null,
	 *   deviceId?:string|null, isKitchenAdmin?:bool, facade?:bool, extRef?:string|null
	 * } $input
	 * @return array{log: ConsumptionLog, replay: bool, httpStatus: int}
	 */
	public function createExpense(array $input): array
	{
		$amount = (int)($input['amountCents'] ?? 0);
		$reason = trim((string)($input['expenseReason'] ?? ''));
		$key = trim((string)$input['idempotencyKey']);
		$siteId = (int)$input['siteId'];
		$actor = (string)$input['actorUserId'];
		$facade = (bool)($input['facade'] ?? false);
		$source = (string)$input['source'];
		$deviceId = $input['deviceId'] ?? null;

		// NN-12: ext: keys are reserved for first-party contract writers — the
		// facade passes facade=true; every human/device caller is refused so a
		// client can never squat a foreign idempotency namespace.
		if (!$facade && str_starts_with($key, 'ext:')) {
			throw new DomainException('idempotency_key_reserved', 'Idempotency key reserved', 422);
		}
		if ($key === '' || mb_strlen($key) > 128) {
			throw new DomainException('validation_failed', 'Idempotency key required', 422);
		}
		if (!$this->settings->isExpenseEnabled()) {
			throw new DomainException('expense_disabled', 'Expenses disabled', 403);
		}
		if ($amount <= 0 || $amount > self::EXPENSE_MAX_CENTS) {
			throw new DomainException('amount_invalid', 'Invalid amount', 422);
		}
		if (mb_strlen($reason) < 3 || mb_strlen($reason) > self::REASON_MAX_LEN) {
			throw new DomainException('expense_reason_required', 'Expense reason required', 422);
		}

		// Attribution: explicit different target = manager/facade credit; otherwise self.
		$target = trim((string)($input['targetUserId'] ?? ''));
		$proxyLike = $target !== '' && $target !== $actor;
		if ($proxyLike) {
			if (!$facade && empty($input['isKitchenAdmin'])) {
				throw new DomainException('permission_denied', 'Expense entry for others requires kitchen admin', 403);
			}
			// Uniform 422 — nonexistent, inaccessible, and off-roster targets must
			// be indistinguishable (no uid/roster oracle, NN-06).
			if ($this->userManager->get($target) === null || !$this->access->canAccessApp($target)) {
				throw new DomainException('validation_failed', 'Cannot log for this user', 422);
			}
			$userId = $target;
		} else {
			if (!$facade) {
				if (!$this->settings->isExpenseSelfEntryEnabled()) {
					throw new DomainException('expense_self_entry_disabled', 'Self expense entry disabled', 403);
				}
				$cap = $this->settings->getExpenseSelfEntryMaxCents();
				if ($cap > 0 && $amount > $cap) {
					throw new DomainException('amount_over_self_limit', 'Amount over self-entry limit', 422);
				}
			}
			$userId = $actor;
		}
		$canonTarget = $proxyLike ? $userId : '';
		$fingerprint = $this->expenseCanonHash($amount, $reason, $siteId, $actor, $canonTarget);

		$existing = $this->mapper->findByIdempotencyKey($key);
		if ($existing !== null) {
			if (!hash_equals($fingerprint, $this->storedExpenseFingerprint($existing))) {
				throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
			}
			$age = $this->timeFactory->getTime() - ($existing->getCreatedAt()?->getTimestamp() ?? 0);
			if ($age > self::IDEMPOTENCY_TTL_HOURS * 3600) {
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
			$existing2 = $this->mapper->findByIdempotencyKey($key);
			if ($existing2 !== null) {
				if (!hash_equals($fingerprint, $this->storedExpenseFingerprint($existing2))) {
					throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
				}
				$this->db->commit();
				return ['log' => $existing2, 'replay' => true, 'httpStatus' => 200];
			}

			$log = new ConsumptionLog();
			$log->setPeriodId((int)$locked->getId());
			$log->setSiteId($siteId);
			$log->setUserId($userId);
			$log->setUserDisplaySnap($this->displayName($userId));
			$log->setItemId(null);
			$log->setItemNameSnap(self::EXPENSE_ITEM_SNAP);
			$log->setQty(1);
			$log->setUnitPriceCents(-$amount);
			$log->setLineTotalCents(-$amount);
			$log->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);
			$log->setSource($source);
			$log->setDeviceId($deviceId !== null ? (string)$deviceId : null);
			$log->setLoggedBy($actor);
			$log->setExpenseReason($reason);
			$log->setIdempotencyKey($key);
			$log->setCreatedAt($this->timeFactory->getDateTime());
			// NN-10 insert-time invariant: a personal_credit row must be negative —
			// a positive credit would silently charge the member instead.
			if ($log->getLineTotalCents() >= 0
				|| $log->getBillingBucket() !== SubsidyService::BUCKET_PERSONAL_CREDIT) {
				throw new \RuntimeException('expense credit sign invariant violated');
			}
			$log = $this->mapper->insert($log);

			// MH-08: audit in the same transaction as the ledger row. The reason
			// text is NEVER audited — amounts + ids only (payload minimization).
			$payload = ['amount_cents' => $amount, 'site_id' => $siteId];
			if ($proxyLike) {
				$payload['target_user_id'] = $userId;
			}
			if ($deviceId !== null) {
				$payload['device_id'] = (string)$deviceId;
			}
			if (!empty($input['extRef'])) {
				$payload['ext_ref'] = (string)$input['extRef'];
			}
			$this->audit->record($actor, $this->expenseAuditAction($source, $proxyLike), 'consumption_log', (string)$log->getId(), $payload);
			$this->db->commit();
			$this->notifyExpenseCredit($userId, $actor, $amount, (int)$log->getId(), $source, $proxyLike);
		} catch (DomainException $e) {
			$this->db->rollBack();
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$again = $this->mapper->findByIdempotencyKey($key);
				if ($again !== null) {
					if (!hash_equals($fingerprint, $this->storedExpenseFingerprint($again))) {
						throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
					}
					return ['log' => $again, 'replay' => true, 'httpStatus' => 200];
				}
			}
			throw $e;
		}

		return ['log' => $log, 'replay' => false, 'httpStatus' => 201];
	}

	/**
	 * NH-08: tell the credited member when SOMEONE ELSE booked the credit —
	 * manager web entry and terminal proxy only. Self-entries, replays, and
	 * facade-written claim rows stay silent (EXP owns its own notification).
	 * Best-effort after commit: a notifier failure must never fail the write.
	 */
	private function notifyExpenseCredit(string $userId, string $actor, int $amount, int $logId, string $source, bool $proxyLike): void
	{
		if (!$proxyLike
			|| !in_array($source, ['expense_admin', 'expense_terminal_proxy'], true)
			|| $this->notificationManager === null) {
			return;
		}
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('snackcheck')
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('expense_credit', (string)$logId)
				->setSubject(\OCA\SnackCheck\Notification\Notifier::SUBJECT_EXPENSE_CREDIT, [
					'amount' => number_format($amount / 100, 2, '.', ''),
					'actor' => $this->displayName($actor),
				]);
			if ($this->urlGenerator !== null) {
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('snackcheck.page.myMonth'));
			}
			$this->notificationManager->notify($notification);
		} catch (\Throwable) {
			// Notifications are informational — never let them break a money write.
		}
	}

	/**
	 * Facade void — EXP is the writer of its own claim rows, so this path
	 * skips web ACLs and the managed_externally block; it only ever touches
	 * source=expense_claim credit rows.
	 */
	public function facadeVoidExpense(int $logId, string $actorUid, string $reason): ConsumptionLog
	{
		return $this->void($logId, $actorUid, $reason, false, false, false, null, true);
	}

	private function expenseAuditAction(string $source, bool $proxyLike): string
	{
		return match ($source) {
			'expense_terminal_proxy' => 'expense.terminal_proxy_create',
			'expense_terminal' => $proxyLike ? 'expense.terminal_proxy_create' : 'expense.terminal_create',
			'expense_claim' => 'expense.facade_create',
			'expense_admin' => 'expense.proxy_create',
			default => $proxyLike ? 'expense.proxy_create' : 'expense.create',
		};
	}

	private function expenseCanonHash(int $amountCents, string $reason, int $siteId, string $actor, string $target): string
	{
		$canon = [
			'amountCents' => $amountCents,
			'expenseReason' => $reason,
			'siteId' => $siteId,
			'actorUserId' => $actor,
			'mode' => 'expense',
			'targetUserId' => $target,
		];
		return hash('sha256', json_encode($canon, JSON_THROW_ON_ERROR));
	}

	private function storedExpenseFingerprint(ConsumptionLog $log): string
	{
		$actor = (string)($log->getLoggedBy() ?? $log->getUserId());
		$userId = (string)$log->getUserId();
		$target = $actor !== $userId ? $userId : '';
		return $this->expenseCanonHash(
			-((int)$log->getLineTotalCents()),
			(string)($log->getExpenseReason() ?? ''),
			(int)$log->getSiteId(),
			$actor,
			$target,
		);
	}

	/**
	 * EXP-managed credit: claim rows stay write-locked to the facade while the
	 * check:expense-claims capability is present (single-writer rule).
	 */
	private function isManagedExternally(ConsumptionLog $log): bool
	{
		if ($log->getBillingBucket() !== SubsidyService::BUCKET_PERSONAL_CREDIT) {
			return false;
		}
		if ($log->getSource() !== 'expense_claim') {
			return false;
		}
		return $this->claimsBridge?->capabilityPresent() ?? false;
	}

	public function void(
		int $logId,
		string $actorUid,
		string $reason,
		bool $isAdmin,
		bool $allowLoggedByActor = false,
		bool $enforceSelfUndoWindow = false,
		?int $requiredSiteId = null,
		bool $facadeManaged = false,
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
			if ($facadeManaged) {
				// Facade void only ever touches its own claim-credit rows.
				if ($log->getBillingBucket() !== SubsidyService::BUCKET_PERSONAL_CREDIT
					|| $log->getSource() !== 'expense_claim') {
					throw new DomainException('managed_externally', 'Not an expense-claim credit', 409);
				}
			} elseif ($enforceSelfUndoWindow) {
				// Permission + undo TTL under the same row lock (closes TOCTOU on window expiry).
				// Uniform 404 for every "not yours / foreign-site" deny — a caller must
				// not diff 403-vs-404 to learn that another user's/kitchen's log exists.
				if ($this->isManagedExternally($log)) {
					// Single-writer: self-undo on EXP-owned rows is a uniform 404
					// (US-009 AC-5) — managers get 409 managed_externally below.
					throw new DomainException('not_found', 'Log not found', 404);
				}
				if ($log->getBillingBucket() === 'company_hospitality') {
					if ((string)$log->getLoggedBy() !== $actorUid) {
						throw new DomainException('not_found', 'Log not found', 404);
					}
				} elseif ($log->getBillingBucket() === SubsidyService::BUCKET_PERSONAL_CREDIT) {
					// Credits may only be self-undone by the entering actor
					// (admin-entered credits are never self-undoable).
					if ((string)$log->getLoggedBy() !== $actorUid) {
						throw new DomainException('not_found', 'Log not found', 404);
					}
				} elseif ($log->getUserId() !== $actorUid && (string)$log->getLoggedBy() !== $actorUid) {
					throw new DomainException('not_found', 'Log not found', 404);
				}
				$created = $log->getCreatedAt()?->getTimestamp() ?? 0;
				if ($this->timeFactory->getTime() - $created > self::UNDO_SECONDS) {
					throw new DomainException('validation_failed', 'Undo window expired', 422);
				}
				// Argus MF: tablet undo must not cross kitchens — bind under the same lock.
				if ($requiredSiteId !== null && (int)$log->getSiteId() !== $requiredSiteId) {
					throw new DomainException('not_found', 'Log not found', 404);
				}
			} elseif ($isAdmin) {
				// Zeus MF: site ACL under the same FOR UPDATE as void — never trust a pre-lock find().
				if (!$this->access->canManageSite($actorUid, (int)$log->getSiteId())) {
					throw new DomainException('not_found', 'Log not found', 404);
				}
				if ($this->isManagedExternally($log)) {
					throw new DomainException('managed_externally', 'Managed by ExpenseCheck', 409);
				}
			} else {
				if ($this->isManagedExternally($log)) {
					throw new DomainException('not_found', 'Log not found', 404);
				}
				$ok = $log->getUserId() === $actorUid
					|| ($allowLoggedByActor && (string)$log->getLoggedBy() === $actorUid);
				if ($log->getBillingBucket() === SubsidyService::BUCKET_PERSONAL_CREDIT) {
					$ok = (string)$log->getLoggedBy() === $actorUid;
				}
				if (!$ok) {
					throw new DomainException('not_found', 'Log not found', 404);
				}
			}
			// unique-conflict:ok this update writes voided_at/voided_by/void_reason only —
			// the unique-indexed idempotency_key column is never re-SET, so the write
			// cannot trip snk_logs_idem_uq; the ->insert race path carries the real catch.
			$log->setVoidedAt($this->timeFactory->getDateTime());
			$log->setVoidedBy($actorUid);
			$log->setVoidReason($reason);
			$log = $this->mapper->update($log);
			// Only the facade path claims the facade_void action — a web manager
			// voiding an orphaned claim row (EXP uninstalled) audits as log.void.
			$voidAction = $facadeManaged ? 'expense.facade_void' : 'log.void';
			$this->audit->record($actorUid, $voidAction, 'consumption_log', (string)$logId, ['reason' => $reason]);
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
			// Uniform target reject (no uid/roster oracle): nonexistent user, user
			// without SnackCheck access, and off-roster user all look identical.
			if ($this->userManager->get($target) === null) {
				throw new DomainException('validation_failed', 'Cannot log for this user', 422);
			}
			if (mb_strlen($reason) < 3) {
				throw new DomainException('proxy_reason_required', 'Proxy reason required', 422);
			}
			if (mb_strlen($reason) > self::REASON_MAX_LEN) {
				throw new DomainException('validation_failed', 'Proxy reason too long', 422);
			}
			// US-008: proxy target must pass the SnackCheck access door.
			if (!$this->access->canAccessApp($target)) {
				throw new DomainException('validation_failed', 'Cannot log for this user', 422);
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
			$bucket = $log->getBillingBucket();
			// Intentionally ignore $siteId — strip must match org-wide My month.
			$line = (int)$log->getLineTotalCents();
			if ($bucket === SubsidyService::BUCKET_PERSONAL_CREDIT) {
				// Quick total = G − C: credits net against the running total.
				$total += $line; // negative
				continue;
			}
			if ($bucket !== SubsidyService::BUCKET_PERSONAL) {
				continue;
			}
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

	/**
	 * Site-local proxy roster seeds: users who already have non-voided charges at this site.
	 *
	 * @return list<string>
	 */
	public function distinctUserIdsForSite(int $siteId): array
	{
		return $this->mapper->distinctUserIdsForSite($siteId);
	}

	/** @param array<string,mixed> $input */
	private function requestFingerprint(array $input): string
	{
		$mode = (string)($input['mode'] ?? 'self');
		$target = '';
		$proxyReason = '';
		$hospReason = '';
		if ($mode === 'proxy') {
			$target = (string)($input['targetUserId'] ?? '');
			$proxyReason = (string)($input['proxyReason'] ?? '');
		} elseif ($mode === 'hospitality') {
			$hospReason = (string)($input['hospitalityReason'] ?? '');
		}
		$canon = [
			'itemId' => (int)$input['itemId'],
			'qty' => (int)$input['qty'],
			'siteId' => (int)$input['siteId'],
			'actorUserId' => (string)($input['actorUserId'] ?? ''),
			'mode' => $mode,
			'targetUserId' => $target,
			'proxyReason' => $proxyReason,
			'hospitalityReason' => $hospReason,
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
