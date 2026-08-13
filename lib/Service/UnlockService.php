<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\UnlockPin;
use OCA\SnackCheck\Db\UnlockPinMapper;
use OCA\SnackCheck\Db\UnlockQr;
use OCA\SnackCheck\Db\UnlockQrMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;

/**
 * PIN/QR unlock maps + short-lived unlockToken issuance.
 * Lockout: 3 failures → progressive soft lockout (30s → 60s → 5m → 15m).
 * Escalation clears on successful unlock; tier TTL bounds the window.
 */
class UnlockService
{
	public const TOKEN_TTL_SECONDS = 120;
	public const LOCKOUT_FAILURES = 3;
	/** First-trip duration (AC-M21); equals LOCKOUT_SCHEDULE_SECONDS[0]. */
	public const LOCKOUT_SECONDS = 30;
	/** @var list<int> */
	public const LOCKOUT_SCHEDULE_SECONDS = [30, 60, 300, 900];
	public const TIER_TTL_SECONDS = 86_400;

	private ICache $cache;

	public function __construct(
		private readonly UnlockPinMapper $pins,
		private readonly UnlockQrMapper $qrs,
		private readonly AccessControlService $access,
		private readonly SettingsService $settings,
		private readonly \OCA\SnackCheck\Db\HospAllowMapper $hospAllow,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $timeFactory,
		private readonly ISecureRandom $random,
		ICacheFactory $cacheFactory,
		private readonly ILockingProvider $locking,
	) {
		$this->cache = $cacheFactory->createDistributed('snackcheck_unlock');
	}

	public function setPin(string $userId, string $pin, string $actorUid): void
	{
		$userId = trim($userId);
		if ($userId === '' || $this->userManager->get($userId) === null) {
			throw new DomainException('validation_failed', 'Unknown user', 422);
		}
		if (!preg_match('/^\d{4,8}$/', $pin)) {
			throw new DomainException('validation_failed', 'PIN must be 4–8 digits', 422);
		}
		$hash = $this->hashPin($pin);
		$clash = $this->pins->findByPinHash($hash);
		if ($clash !== null && $clash->getUserId() !== $userId) {
			throw new DomainException('validation_failed', 'PIN already in use', 422);
		}
		$now = $this->timeFactory->getDateTime();
		$existing = $this->pins->findByUserId($userId);
		if ($existing !== null) {
			$existing->setPinHash($hash);
			$existing->setUpdatedAt($now);
			$existing->setUpdatedBy($actorUid);
			$this->pins->update($existing);
			return;
		}
		$row = new UnlockPin();
		$row->setUserId($userId);
		$row->setPinHash($hash);
		$row->setFailCount(0);
		$row->setLockedUntil(null);
		$row->setUpdatedAt($now);
		$row->setUpdatedBy($actorUid);
		$this->pins->insert($row);
	}

	public function setQr(string $userId, string $payload, string $actorUid): void
	{
		$userId = trim($userId);
		if ($userId === '' || $this->userManager->get($userId) === null) {
			throw new DomainException('validation_failed', 'Unknown user', 422);
		}
		$payload = trim($payload);
		if ($payload === '') {
			throw new DomainException('validation_failed', 'QR payload required', 422);
		}
		if (strlen($payload) < 4) {
			throw new DomainException('validation_failed', 'QR/NFC payload too short', 422);
		}
		$hash = $this->hashQr($payload);
		$clash = $this->qrs->findByTokenHash($hash);
		if ($clash !== null && $clash->getUserId() !== $userId) {
			throw new DomainException('validation_failed', 'QR already in use', 422);
		}
		$now = $this->timeFactory->getDateTime();
		$existing = $this->qrs->findByUserId($userId);
		if ($existing !== null) {
			$existing->setTokenHash($hash);
			$existing->setUpdatedAt($now);
			$existing->setUpdatedBy($actorUid);
			$this->qrs->update($existing);
			return;
		}
		$row = new UnlockQr();
		$row->setUserId($userId);
		$row->setTokenHash($hash);
		$row->setUpdatedAt($now);
		$row->setUpdatedBy($actorUid);
		$this->qrs->insert($row);
	}

	/**
	 * @return array{unlockToken:string,userId:string,userDisplayName:string,expiresAt:string,isKitchenAdmin:bool,hospitalityAllowed:bool}
	 */
	public function verify(
		?string $pin,
		?string $qrPayload,
		string $deviceKey,
		?string $nfcPayload = null,
		?int $deviceSiteId = null,
		string $bindDeviceId = '',
	): array {
		$lockKey = 'lockout:' . $deviceKey;
		$failKey = 'fails:' . $deviceKey;
		$lockUntil = $this->cache->get($lockKey);
		if (is_int($lockUntil) && $lockUntil > $this->timeFactory->getTime()) {
			$retry = max(1, $lockUntil - $this->timeFactory->getTime());
			throw new DomainException('unlock_lockout', 'Locked out', 429, $retry);
		}

		// NFC badges map to the same token store as QR (AC-OPP-S1).
		if ((!$qrPayload || $qrPayload === '') && is_string($nfcPayload) && $nfcPayload !== '') {
			$qrPayload = $nfcPayload;
		}

		$userId = null;
		if (is_string($pin) && $pin !== '') {
			$row = $this->pins->findByPinHash($this->hashPin($pin));
			// Soft-upgrade: accept pre-pepper hashes once, then rehash under pepper.
			if ($row === null) {
				$legacy = hash('sha256', 'snk-pin|' . $pin);
				$row = $this->pins->findByPinHash($legacy);
				if ($row !== null) {
					$this->setPin((string)$row->getUserId(), $pin, 'system-rehash');
					$row = $this->pins->findByPinHash($this->hashPin($pin)) ?? $row;
				}
			}
			$userId = $row?->getUserId();
		} elseif (is_string($qrPayload) && $qrPayload !== '') {
			$row = $this->qrs->findByTokenHash($this->hashQr($qrPayload));
			if ($row === null) {
				$legacy = hash('sha256', $qrPayload);
				$row = $this->qrs->findByTokenHash($legacy);
				if ($row !== null) {
					$this->setQr((string)$row->getUserId(), $qrPayload, 'system-rehash');
					$row = $this->qrs->findByTokenHash($this->hashQr($qrPayload)) ?? $row;
				}
			}
			$userId = $row?->getUserId();
		} else {
			throw new DomainException('unlock_invalid', 'PIN, QR, or NFC payload required', 422);
		}

		if ($userId === null || $userId === '') {
			$this->recordUnlockFailure($deviceKey, $failKey, $lockKey);
			throw new DomainException('unlock_invalid', 'Invalid unlock', 401);
		}

		// Access door: listed ACL must not be bypassable via leftover PIN.
		// Argus MF: same response + fail accounting as wrong PIN (no 401/403 oracle).
		if (!$this->access->canAccessApp($userId)) {
			$this->recordUnlockFailure($deviceKey, $failKey, $lockKey);
			throw new DomainException('unlock_invalid', 'Invalid unlock', 401);
		}

		$this->cache->remove($failKey);
		$this->cache->remove('tier:' . $deviceKey);
		$token = 'snkunlock_' . $this->random->generate(48);
		$expires = $this->timeFactory->getTime() + self::TOKEN_TTL_SECONDS;
		$isKitchenAdmin = $this->access->isAppAdmin($userId);
		if (!$isKitchenAdmin && $deviceSiteId !== null && $deviceSiteId > 0) {
			$isKitchenAdmin = $this->access->canManageSite($userId, $deviceSiteId);
		} elseif (!$isKitchenAdmin) {
			$isKitchenAdmin = $this->access->isKitchenManager($userId);
		}
		$boundDeviceId = $bindDeviceId !== '' ? $bindDeviceId : $deviceKey;
		$session = [
			'userId' => $userId,
			'expiresAt' => $expires,
			'isKitchenAdmin' => $isKitchenAdmin,
			'hospitalityAllowed' => $this->settings->isHospitalityEnabled() && $this->hospAllow->isAllowed($userId),
			'deviceId' => $boundDeviceId,
		];
		$this->cache->set('tok:' . hash('sha256', $token), $session, self::TOKEN_TTL_SECONDS);

		$user = $this->userManager->get($userId);
		$display = $user?->getDisplayName() ?: $userId;

		return [
			'unlockToken' => $token,
			'userId' => $userId,
			'userDisplayName' => $display,
			'expiresAt' => (new \DateTimeImmutable('@' . $expires))->format('c'),
			'isKitchenAdmin' => $session['isKitchenAdmin'],
			'hospitalityAllowed' => $session['hospitalityAllowed'],
		];
	}

	/**
	 * Validate unlock token without invalidating.
	 * Session stays valid for the full TTL so self-undo / multi-tap work (COMPANION §8).
	 * Call {@see invalidateUnlockToken} on explicit lock-out from the tablet.
	 * When $requiredDeviceId is set, the token must have been issued for that device.
	 *
	 * @return array{userId:string,isKitchenAdmin:bool,hospitalityAllowed:bool}
	 */
	public function peekUnlockToken(string $token, ?string $requiredDeviceId = null): array
	{
		return $this->readUnlockSession($token, $requiredDeviceId);
	}

	/**
	 * @deprecated Alias of peekUnlockToken — name kept for older call sites. Does NOT consume.
	 * @return array{userId:string,isKitchenAdmin:bool,hospitalityAllowed:bool}
	 */
	public function consumeUnlockToken(string $token, ?string $requiredDeviceId = null): array
	{
		return $this->readUnlockSession($token, $requiredDeviceId);
	}

	/**
	 * Explicit tablet lock-out. When $requiredDeviceId is set, only that device may kill the session
	 * (prevents one paired tablet from invalidating another device's unlockToken).
	 */
	public function invalidateUnlockToken(string $token, ?string $requiredDeviceId = null): void
	{
		$token = trim($token);
		if ($token === '') {
			return;
		}
		if ($requiredDeviceId !== null && $requiredDeviceId !== '') {
			$this->readUnlockSession($token, $requiredDeviceId);
		}
		$this->cache->remove('tok:' . hash('sha256', $token));
	}

	/**
	 * @return array{userId:string,isKitchenAdmin:bool,hospitalityAllowed:bool}
	 */
	private function readUnlockSession(string $token, ?string $requiredDeviceId = null): array
	{
		$key = 'tok:' . hash('sha256', $token);
		$session = $this->cache->get($key);
		if (!is_array($session) || !isset($session['userId'], $session['expiresAt'])) {
			throw new DomainException('unlock_invalid', 'Invalid unlock token', 401);
		}
		if ((int)$session['expiresAt'] < $this->timeFactory->getTime()) {
			$this->cache->remove($key);
			throw new DomainException('unlock_invalid', 'Unlock token expired', 401);
		}
		if ($requiredDeviceId !== null && $requiredDeviceId !== '') {
			$bound = (string)($session['deviceId'] ?? '');
			if ($bound === '' || !hash_equals($bound, $requiredDeviceId)) {
				throw new DomainException('unlock_invalid', 'Invalid unlock token', 401);
			}
		}
		return [
			'userId' => (string)$session['userId'],
			'isKitchenAdmin' => !empty($session['isKitchenAdmin']),
			'hospitalityAllowed' => !empty($session['hospitalityAllowed']),
		];
	}

	private function hashPin(string $pin): string
	{
		// Deterministic + peppered: unique PINs org-wide, DB dump alone cannot rainbow.
		return hash_hmac('sha256', $pin, 'snk-pin|' . $this->settings->getUnlockPepper());
	}

	private function hashQr(string $payload): string
	{
		// Pepper QR/NFC payloads — badge UIDs are often low-entropy without this.
		return hash_hmac('sha256', $payload, 'snk-qr|' . $this->settings->getUnlockPepper());
	}

	/** Serialize fail counter increments so concurrent bad PINs cannot skip lockout. */
	private function recordUnlockFailure(string $deviceKey, string $failKey, string $lockKey): void
	{
		$lockName = 'snackcheck/unlock_fail/' . hash('sha256', $deviceKey);
		$acquired = false;
		try {
			$this->locking->acquireLock($lockName, ILockingProvider::LOCK_EXCLUSIVE);
			$acquired = true;
		} catch (LockedException) {
			// Contended — treat as soft lockout rather than under-counting.
			throw new DomainException('unlock_lockout', 'Locked out', 429, self::LOCKOUT_SECONDS);
		}
		try {
			$fails = (int)($this->cache->get($failKey) ?? 0) + 1;
			$this->cache->set($failKey, $fails, self::LOCKOUT_SECONDS * 2);
			if ($fails >= self::LOCKOUT_FAILURES) {
				$tierKey = 'tier:' . $deviceKey;
				$tier = (int)($this->cache->get($tierKey) ?? 0);
				$schedule = self::LOCKOUT_SCHEDULE_SECONDS;
				$duration = $schedule[min($tier, count($schedule) - 1)];
				$until = $this->timeFactory->getTime() + $duration;
				$this->cache->set($lockKey, $until, $duration);
				$this->cache->set($tierKey, $tier + 1, self::TIER_TTL_SECONDS);
				$this->cache->remove($failKey);
				throw new DomainException('unlock_lockout', 'Locked out', 429, $duration);
			}
		} finally {
			if ($acquired) {
				$this->locking->releaseLock($lockName, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}
}
