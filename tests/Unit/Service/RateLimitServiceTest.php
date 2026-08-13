<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

final class RateLimitServiceTest extends TestCase
{
	public function testAllowsUnderLimitThenBlocks(): void
	{
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$rl = new RateLimitService($factory, $time, $this->createMock(\OCP\Lock\ILockingProvider::class));
		for ($i = 0; $i < 60; $i++) {
			$rl->assertUserLog('u1');
		}
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Rate limited');
		$rl->assertUserLog('u1');
	}

	public function testDeviceBucketsAreIndependent(): void
	{
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_060);

		$rl = new RateLimitService($factory, $time, $this->createMock(\OCP\Lock\ILockingProvider::class));
		$rl->assertDeviceLog('d1');
		$rl->assertDeviceUnlock('d1');
		self::assertTrue(true);
	}

	public function testDeviceUnlockSoftCapIsTenPerMinute(): void
	{
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_120);

		$rl = new RateLimitService($factory, $time, $this->createMock(\OCP\Lock\ILockingProvider::class));
		for ($i = 0; $i < 10; $i++) {
			$rl->assertDeviceUnlock('tablet-a');
		}
		$this->expectException(DomainException::class);
		$rl->assertDeviceUnlock('tablet-a');
	}

	public function testLockContentionIsRateLimited(): void
	{
		$cache = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_200);
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new \OCP\Lock\LockedException('busy'));

		$rl = new RateLimitService($factory, $time, $locking);
		$this->expectException(DomainException::class);
		try {
			$rl->assertUserLog('alice');
		} catch (DomainException $e) {
			self::assertSame('rate_limited', $e->errorCode);
			throw $e;
		}
	}

	public function testHitAcquiresAndReleasesExclusiveLock(): void
	{
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_300);
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$rl = new RateLimitService($factory, $time, $locking);
		$rl->assertUserLog('bob');
	}
}
