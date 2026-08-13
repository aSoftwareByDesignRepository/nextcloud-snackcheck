<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Controller;

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Exception\PaymentRequiredException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\RateLimitService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Kitchen tablet device API — Bearer snkterm_.
 * Responses match COMPANION / kiosk TypeScript contracts (unwrapped JSON).
 * Web routes must NEVER return 402; this controller may.
 */
class DeviceApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TerminalDeviceService $terminals,
		private readonly LicenseService $license,
		private readonly CatalogService $catalog,
		private readonly PeriodService $periods,
		private readonly SiteService $sites,
		private readonly UnlockService $unlock,
		private readonly ConsumptionLogService $logs,
		private readonly SettingsService $settings,
		private readonly AccessControlService $access,
		private readonly RateLimitService $rateLimit,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$open = $this->periods->findOpen();
			$periodPayload = null;
			if ($open !== null) {
				$periodPayload = [
					'id' => $open->getId(),
					'label' => $open->getLabel(),
					'state' => $open->getState(),
				];
			} else {
				$closed = $this->periods->findLatestClosed();
				$periodPayload = [
					'id' => $closed?->getId(),
					'label' => $closed?->getLabel() ?? '',
					'state' => 'closed',
				];
			}
			$site = $this->sites->get((int)$device->getSiteId());
			$items = $this->catalog->listActive((int)$device->getSiteId());
			$envelope = $this->license->buildEnvelope();
			$planActive = $this->license->isTerminalPlanActive();
			return new JSONResponse([
				'serverTime' => $this->timeFactory->getDateTime()->format('c'),
				'period' => $periodPayload,
				'catalogVersion' => self::catalogVersionToken($items),
				'capabilities' => [
					'snackcheck.deviceApi' => 1,
					'minClient' => 1,
					// NFC uses same server map as QR when hardware present (client optional).
					'unlock' => ['pin', 'qr', 'nfc'],
					'selfUndo' => true,
				],
				// Client expects envelope at licensing (not licensing.envelope)
				'licensing' => $envelope,
				'vendorPublicKeyB64' => \OCA\SnackCheck\Config\VendorPublicKey::DEFAULT_PUBLIC_KEY_B64,
				'terminalPlanActive' => $planActive,
				'licenseAccess' => $planActive ? 'ok' : 'required',
				'hospitalityEnabled' => $this->settings->isHospitalityEnabled(),
				'device' => [
					'id' => $device->getId(),
					'label' => $device->getLabel(),
					'siteId' => $device->getSiteId(),
					'siteCode' => $site->getCode(),
					'siteName' => $site->getName(),
				],
			]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function catalog(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$items = $this->catalog->listActive((int)$device->getSiteId());
			$favoriteIds = [];
			$token = (string)($this->request->getHeader('X-Unlock-Token') ?: '');
			if ($token !== '') {
				try {
					$session = $this->unlock->peekUnlockToken($token, (string)$device->getId());
					$favoriteIds = array_fill_keys(
						$this->logs->lastItemIdsForUser($session['userId'], (int)$device->getSiteId(), 5),
						true
					);
				} catch (\Throwable) {
					$favoriteIds = [];
				}
			}
			$out = [];
			foreach ($items as $i) {
				$tags = [];
				$raw = $i->getTagsJson();
				if (is_string($raw) && $raw !== '') {
					try {
						$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
						if (is_array($decoded)) {
							$tags = array_values(array_filter($decoded, 'is_string'));
						}
					} catch (\JsonException) {
						$tags = [];
					}
				}
				$id = (int)$i->getId();
				$out[] = [
					'id' => $id,
					'name' => $i->getName(),
					'priceCents' => $i->getPriceCents(),
					'category' => $i->getCategory(),
					'allergenTags' => $tags,
					'favorite' => isset($favoriteIds[$id]),
					'active' => ((int)$i->getActive()) === 1,
				];
			}
			$body = [
				'items' => $out,
				'catalogVersion' => self::catalogVersionToken($items),
			];
			$etag = '"' . hash('sha256', (string)json_encode($body)) . '"';
			$ifNone = $this->request->getHeader('If-None-Match');
			if (is_string($ifNone) && $ifNone !== '' && hash_equals($etag, $ifNone)) {
				return new JSONResponse(null, Http::STATUS_NOT_MODIFIED);
			}
			$res = new JSONResponse($body);
			$res->addHeader('ETag', $etag);
			return $res;
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function unlockVerify(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$this->rateLimit->assertDeviceUnlock((string)$device->getId());
			$body = $this->jsonBody();
			$result = $this->unlock->verify(
				isset($body['pin']) ? (string)$body['pin'] : $this->request->getParam('pin'),
				isset($body['qrPayload']) ? (string)$body['qrPayload'] : $this->request->getParam('qrPayload'),
				'dev:' . $device->getId(),
				isset($body['nfcPayload']) ? (string)$body['nfcPayload'] : $this->request->getParam('nfcPayload'),
				(int)$device->getSiteId(),
				(string)$device->getId(),
			);
			$quick = $this->logs->quickTotalCentsForUser($result['userId'], (int)$device->getSiteId());
			return new JSONResponse([
				'unlockToken' => $result['unlockToken'],
				'userId' => $result['userId'],
				'userDisplayName' => $result['userDisplayName'],
				'expiresAt' => $result['expiresAt'],
				'isKitchenAdmin' => $result['isKitchenAdmin'],
				'canHospitality' => $result['hospitalityAllowed'],
				'quickTotalCents' => $quick,
			]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function createLog(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$body = $this->jsonBody();
			$token = (string)($body['unlockToken'] ?? $this->request->getParam('unlockToken') ?? '');
			$session = $this->unlock->peekUnlockToken($token, (string)$device->getId());
			// Device 120/min already applied in authenticateDevice (COMPANION §7.5).
			// CORE §9.7 also requires per-user 60 logs/min on the tablet path.
			$this->rateLimit->assertUserLog($session['userId']);
			$mode = (string)($body['mode'] ?? $this->request->getParam('mode') ?? 'self');
			// Never trust cached isKitchenAdmin for the full unlock TTL — re-check live ACL.
			$liveKitchenAdmin = $this->isLiveKitchenAdmin($session['userId'], (int)$device->getSiteId());
			$idem = (string)($this->request->getHeader('Idempotency-Key')
				?: ($body['idempotencyKey'] ?? $this->request->getParam('idempotencyKey') ?? ''));
			$result = $this->logs->create([
				'itemId' => (int)($body['itemId'] ?? $this->request->getParam('itemId')),
				'qty' => (int)($body['qty'] ?? $this->request->getParam('qty') ?? 1),
				'idempotencyKey' => $idem,
				'siteId' => (int)$device->getSiteId(),
				'actorUserId' => $session['userId'],
				'source' => $mode === 'hospitality' ? 'hospitality_terminal' : 'terminal',
				'mode' => $mode,
				'targetUserId' => $body['targetUserId'] ?? $this->request->getParam('targetUserId'),
				'proxyReason' => $body['proxyReason'] ?? $this->request->getParam('proxyReason'),
				'hospitalityReason' => $body['hospitalityReason'] ?? $this->request->getParam('hospitalityReason'),
				'deviceId' => (string)$device->getId(),
				'isKitchenAdmin' => $liveKitchenAdmin,
			]);
			$log = $result['log'];
			$created = $log->getCreatedAt() ?? $this->timeFactory->getDateTime();
			$undoUntil = (clone $created)->modify('+' . ConsumptionLogService::UNDO_SECONDS . ' seconds');
			return new JSONResponse([
				'id' => $log->getId(),
				'itemId' => $log->getItemId(),
				'itemName' => $log->getItemNameSnap(),
				'qty' => $log->getQty(),
				'lineTotalCents' => $log->getLineTotalCents(),
				'undoUntil' => $undoUntil->format('c'),
				'mode' => $mode,
				'replay' => $result['replay'],
			], $result['httpStatus']);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function undoLog(int $id): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$body = $this->jsonBody();
			$token = (string)($body['unlockToken'] ?? $this->request->getParam('unlockToken') ?? '');
			$session = $this->unlock->peekUnlockToken($token, (string)$device->getId());
			// Argus MF: tablet undo is site-scoped — never void another kitchen's ledger row.
			$this->logs->selfUndo($id, $session['userId'], (int)$device->getSiteId());
			return new JSONResponse(['ok' => true, 'id' => $id]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function colleagues(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$token = (string)($this->request->getHeader('X-Unlock-Token') ?: $this->request->getParam('unlockToken') ?? '');
			$session = $this->unlock->peekUnlockToken($token, (string)$device->getId());
			// Live ACL — do not trust session.isKitchenAdmin for the full unlock TTL.
			if (!$this->isLiveKitchenAdmin($session['userId'], (int)$device->getSiteId())) {
				throw new DomainException('permission_denied', 'Kitchen admin required', 403);
			}
			$q = trim((string)($this->request->getParam('q') ?? ''));
			$limit = min(50, max(1, (int)($this->request->getParam('limit') ?? 50)));
			$colleagues = [];
			foreach ($this->userManager->search($q, $limit) as $user) {
				$uid = $user->getUID();
				if ($uid === $session['userId']) {
					continue;
				}
				if (!$this->access->canAccessApp($uid)) {
					continue;
				}
				$colleagues[] = [
					'userId' => $uid,
					'displayName' => $user->getDisplayName() ?: $uid,
				];
			}
			usort($colleagues, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
			return new JSONResponse(['colleagues' => $colleagues]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function lockSession(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$body = $this->jsonBody();
			$token = (string)($body['unlockToken'] ?? $this->request->getParam('unlockToken') ?? '');
			$this->unlock->invalidateUnlockToken($token, (string)$device->getId());
			return new JSONResponse(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function heartbeat(): JSONResponse
	{
		try {
			$this->authenticateDevice();
			return new JSONResponse(['serverTime' => $this->timeFactory->getDateTime()->format('c')]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	/**
	 * Optional P1.1 self-revoke (COMPANION §7.4) — tablet clears credentials after 2xx.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function unpair(): JSONResponse
	{
		try {
			$device = $this->authenticateDevice();
			$result = $this->terminals->revoke((int)$device->getId(), 'device:' . $device->getId());
			if (!$result['ok']) {
				throw new DomainException('terminal_not_found', 'Device not found', 404);
			}
			return new JSONResponse(['ok' => true]);
		} catch (\Throwable $e) {
			return $this->deviceFail($e);
		}
	}

	private function authenticateDevice(): \OCA\SnackCheck\Db\TerminalDevice
	{
		$auth = $this->request->getHeader('Authorization');
		$device = $this->terminals->resolveToken($auth);
		if ($device === null) {
			throw new DomainException('no_device', 'Device not found', 401);
		}
		if (!$this->license->isTerminalPlanActive()) {
			throw new PaymentRequiredException('license_required');
		}
		// AC-M9: over-cap after restore / limit shrink must not keep serving.
		$limit = $this->terminals->getDeviceLimit();
		if ($this->terminals->getActiveCount() > $limit) {
			throw new PaymentRequiredException('license_required');
		}
		// COMPANION §7.5: device-wide 120/min before handler work.
		$this->rateLimit->assertDeviceApi((string)$device->getId());
		return $device;
	}

	/** Live kitchen-admin check — never rely solely on unlock-session cache for privileged modes. */
	private function isLiveKitchenAdmin(string $userId, int $siteId): bool
	{
		if ($this->access->isAppAdmin($userId)) {
			return true;
		}
		if ($siteId > 0) {
			return $this->access->canManageSite($userId, $siteId);
		}
		return $this->access->isKitchenManager($userId);
	}

	/**
	 * Content-sensitive catalog revision token (not a row count — delete+add same N must change).
	 *
	 * @param list<\OCA\SnackCheck\Db\CatalogItem> $items
	 */
	public static function catalogVersionToken(array $items): string
	{
		$parts = [];
		foreach ($items as $i) {
			$parts[] = implode(':', [
				(int)$i->getId(),
				(string)$i->getName(),
				(int)$i->getPriceCents(),
				(string)$i->getCategory(),
				(string)($i->getTagsJson() ?? ''),
				(int)$i->getActive(),
				(string)($i->getUpdatedAt()?->getTimestamp() ?? 0),
			]);
		}
		sort($parts);
		return substr(hash('sha256', implode('|', $parts)), 0, 16);
	}

	/** @return array<string, mixed> */
	private function jsonBody(): array
	{
		$raw = file_get_contents('php://input');
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		return is_array($decoded) ? $decoded : [];
	}

	private function deviceFail(\Throwable $e): JSONResponse
	{
		if ($e instanceof PaymentRequiredException) {
			return new JSONResponse([
				'code' => 'license_required',
				'error' => 'license_required',
				'ok' => false,
			], Http::STATUS_PAYMENT_REQUIRED);
		}
		if ($e instanceof DomainException) {
			$body = [
				'code' => $e->errorCode,
				'error' => $e->errorCode,
				'message' => $e->getMessage(),
				'ok' => false,
			];
			$retry = $e->retryAfterSeconds;
			if ($retry === null && $e->httpStatus === 429) {
				$retry = \OCA\SnackCheck\Service\RateLimitService::RETRY_AFTER_SECONDS;
			}
			if ($retry !== null && $retry > 0) {
				$body['retryAfter'] = $retry;
			}
			$res = new JSONResponse($body, $e->httpStatus);
			if ($e->httpStatus === 429 && $retry !== null && $retry > 0) {
				$res->addHeader('Retry-After', (string)$retry);
			}
			return $res;
		}
		return new JSONResponse([
			'code' => 'server_error',
			'error' => 'server_error',
			'ok' => false,
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
