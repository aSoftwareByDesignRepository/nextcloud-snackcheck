<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Soft rate limits (CORE §9.7 + COMPANION §7.5):
 * - device API: 120 req/min shared bucket (middleware on every Bearer route)
 * - user logs: 60/min
 * - unlock verify: 10/min (additional soft cap)
 * Hits are serialized per bucket via exclusive lock to close TOCTOU stampede gaps.
 */
class RateLimitService
{
	private const USER_LOG_LIMIT = 60;
	private const DEVICE_API_LIMIT = 120;
	private const DEVICE_UNLOCK_LIMIT = 10;
	private const WINDOW = 60;
	public const RETRY_AFTER_SECONDS = 60;

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $locking,
	) {
		$this->cache = $cacheFactory->createDistributed('snackcheck_rl');
	}

	/** COMPANION §7.5 step 3 — every authenticated device route. */
	public function assertDeviceApi(string $deviceId): void
	{
		$this->hit('dapi:' . $deviceId, self::DEVICE_API_LIMIT);
	}

	public function assertUserLog(string $userId): void
	{
		$this->hit('ulog:' . $userId, self::USER_LOG_LIMIT);
	}

	/**
	 * @deprecated Prefer assertDeviceApi — kept as alias so log-path contracts stay explicit.
	 */
	public function assertDeviceLog(string $deviceId): void
	{
		$this->assertDeviceApi($deviceId);
	}

	public function assertDeviceUnlock(string $deviceId): void
	{
		$this->hit('dunl:' . $deviceId, self::DEVICE_UNLOCK_LIMIT);
	}

	private function hit(string $key, int $limit): void
	{
		$bucket = $key . ':' . intdiv($this->timeFactory->getTime(), self::WINDOW);
		$lockKey = 'snackcheck/rl/' . hash('sha256', $bucket);
		$acquired = false;
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			$acquired = true;
		} catch (LockedException) {
			throw new DomainException('rate_limited', 'Rate limited', 429);
		}
		try {
			$count = (int)($this->cache->get($bucket) ?? 0) + 1;
			$this->cache->set($bucket, $count, self::WINDOW + 5);
			if ($count > $limit) {
				throw new DomainException('rate_limited', 'Rate limited', 429);
			}
		} finally {
			if ($acquired) {
				$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}
}
