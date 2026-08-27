<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\LockGate;
use OCA\SnackCheck\Db\TerminalDevice;
use OCA\SnackCheck\Db\TerminalDeviceMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class TerminalDeviceService
{
	public const TOKEN_PREFIX = 'snkterm_';
	/**
	 * Zeus MF-03: capacity seat races use DB LockGate (snk_locks.terminal_capacity FOR UPDATE),
	 * not node-local file locks. Constant kept for contracts/mutation asserts.
	 */
	public const CAPACITY_LOCK = LockGate::KEY_TERMINAL_CAPACITY;
	/**
	 * Zeus MF: stolen long-lived Bearers must not work forever.
	 * Devices idle longer than this (no last_seen / never seen since register) are rejected until re-registered.
	 */
	public const MAX_IDLE_SECONDS = 7776000; // 90 days

	public function __construct(
		private readonly TerminalDeviceMapper $mapper,
		private readonly LicenseService $licenseService,
		private readonly IDBConnection $db,
		private readonly LockGate $lockGate,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function getActiveCount(): int
	{
		return $this->mapper->countActive();
	}

	public function getDeviceLimit(): int
	{
		return $this->licenseService->getTerminalDeviceLimit();
	}

	/** @return list<array<string,mixed>> */
	public function listActive(): array
	{
		$out = [];
		foreach ($this->mapper->findAllActiveOrdered() as $device) {
			$out[] = $this->present($device);
		}
		return $out;
	}

	/**
	 * @return array{ok:bool,error?:string,device?:array<string,mixed>,deviceToken?:string}
	 */
	public function register(string $actorUserId, string $label, int $siteId): array
	{
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > 128) {
			return ['ok' => false, 'error' => 'invalid_label'];
		}
		if ($siteId <= 0) {
			return ['ok' => false, 'error' => 'invalid_site'];
		}
		if (!$this->licenseService->isTerminalPlanActive()) {
			return ['ok' => false, 'error' => 'no_terminal_plan'];
		}

		$this->db->beginTransaction();
		try {
			// DB gate serializes register/trim across all NC app servers sharing this database.
			$this->lockGate->lock(self::CAPACITY_LOCK);
			if ($this->mapper->countActive() >= $this->getDeviceLimit()) {
				$this->db->rollBack();
				return ['ok' => false, 'error' => 'terminal_limit_reached'];
			}
			$plain = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
			$now = $this->timeFactory->getDateTime();
			$device = new TerminalDevice();
			$device->setLabel($label);
			$device->setSiteId($siteId);
			$device->setTokenHash(hash('sha256', $plain));
			$device->setRegisteredAt($now);
			$device->setRegisteredBy($actorUserId);
			$device->setLastSeenAt(null);
			$device->setRevoked(0);
			$device = $this->mapper->insert($device);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return [
			'ok' => true,
			'device' => $this->present($device),
			'deviceToken' => $plain,
			'terminals' => $this->listActive(),
			'terminalDevicesUsed' => $this->getActiveCount(),
			'terminalDevicesLimit' => $this->getDeviceLimit(),
		];
	}

	/** @return array{ok:bool,error?:string} */
	public function revoke(int $deviceId, string $actor): array
	{
		$this->db->beginTransaction();
		try {
			$this->lockGate->lock(self::CAPACITY_LOCK);
			$device = $this->mapper->findActiveById($deviceId);
			if ($device === null) {
				$this->db->rollBack();
				return ['ok' => false, 'error' => 'terminal_not_found'];
			}
			$device->setRevoked(1);
			$this->mapper->update($device);
			$this->db->commit();
			return ['ok' => true];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Aristoteles: deactivating a kitchen must free tablet seats under the same capacity gate.
	 *
	 * @return int number of devices revoked
	 */
	public function revokeAllBySite(int $siteId, string $actor): int
	{
		if ($siteId <= 0) {
			return 0;
		}
		$this->db->beginTransaction();
		try {
			$this->lockGate->lock(self::CAPACITY_LOCK);
			$revoked = 0;
			foreach ($this->mapper->findActiveBySite($siteId) as $device) {
				$device->setRevoked(1);
				$this->mapper->update($device);
				$revoked++;
			}
			$this->db->commit();
			return $revoked;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function trimToLimit(int $limit): int
	{
		$limit = max(0, $limit);
		$this->db->beginTransaction();
		try {
			$this->lockGate->lock(self::CAPACITY_LOCK);
			$devices = $this->mapper->findAllActiveOrdered();
			if (count($devices) <= $limit) {
				$this->db->commit();
				return 0;
			}
			$toRevoke = array_slice($devices, $limit);
			$revoked = 0;
			foreach ($toRevoke as $device) {
				$device->setRevoked(1);
				$this->mapper->update($device);
				$revoked++;
			}
			$this->db->commit();
			return $revoked;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function resolveToken(?string $authorizationHeader): ?TerminalDevice
	{
		if ($authorizationHeader === null || !str_starts_with($authorizationHeader, 'Bearer ')) {
			return null;
		}
		$token = trim(substr($authorizationHeader, 7));
		if (!str_starts_with($token, self::TOKEN_PREFIX)) {
			return null;
		}
		$device = $this->mapper->findActiveByTokenHash(hash('sha256', $token));
		if ($device === null) {
			return null;
		}
		$now = $this->timeFactory->getDateTime();
		$anchor = $device->getLastSeenAt() ?? $device->getRegisteredAt();
		if ($anchor !== null) {
			$idle = $now->getTimestamp() - $anchor->getTimestamp();
			if ($idle > self::MAX_IDLE_SECONDS) {
				// Fail closed: do not refresh last_seen for abandoned/stolen credentials.
				return null;
			}
		}
		$last = $device->getLastSeenAt();
		$shouldTouch = $last === null || ($now->getTimestamp() - $last->getTimestamp()) >= 60;
		if ($shouldTouch) {
			$device->setLastSeenAt($now);
			$this->mapper->update($device);
		}
		return $device;
	}

	/** @return array<string,mixed> */
	private function present(TerminalDevice $device): array
	{
		return [
			'id' => $device->getId(),
			'label' => $device->getLabel(),
			'siteId' => $device->getSiteId(),
			'registeredAt' => $device->getRegisteredAt()?->format('c') ?? '',
			'registeredBy' => $device->getRegisteredBy(),
			'lastSeenAt' => $device->getLastSeenAt()?->format('c'),
		];
	}
}
