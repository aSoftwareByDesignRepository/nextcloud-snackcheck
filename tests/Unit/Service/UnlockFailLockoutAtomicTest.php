<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\UnlockPinMapper;
use OCA\SnackCheck\Db\UnlockQrMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/** Unlock fail counter must be serialized (no TOCTOU under concurrent bad PINs). */
final class UnlockFailLockoutAtomicTest extends TestCase
{
	public function testLockContentionYieldsLockout(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('busy'));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUnlockPepper')->willReturn('test-pepper-32-characters-long!!');

		$svc = new UnlockService(
			$this->createMock(UnlockPinMapper::class),
			$this->createMock(UnlockQrMapper::class),
			$this->createMock(AccessControlService::class),
			$settings,
			$this->createMock(HospAllowMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(ISecureRandom::class),
			$factory,
			$locking,
		);

		$this->expectException(DomainException::class);
		try {
			$svc->verify('9999', null, 'dev-1');
		} catch (DomainException $e) {
			self::assertSame('unlock_lockout', $e->errorCode);
			self::assertSame(30, $e->retryAfterSeconds);
			throw $e;
		}
	}

	public function testThirdFailureLocksOutUnderExclusiveLock(): void
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
		$cache->method('remove')->willReturnCallback(static function (string $key) use (&$store) {
			unset($store[$key]);
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->exactly(3))->method('acquireLock');
		$locking->expects($this->exactly(3))->method('releaseLock');

		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->willReturn(null);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUnlockPepper')->willReturn('test-pepper-32-characters-long!!');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$svc = new UnlockService(
			$pins,
			$this->createMock(UnlockQrMapper::class),
			$this->createMock(AccessControlService::class),
			$settings,
			$this->createMock(HospAllowMapper::class),
			$this->createMock(IUserManager::class),
			$time,
			$this->createMock(ISecureRandom::class),
			$factory,
			$locking,
		);

		for ($i = 0; $i < 2; $i++) {
			try {
				$svc->verify('0000', null, 'tablet');
				self::fail('expected unlock_invalid');
			} catch (DomainException $e) {
				self::assertSame('unlock_invalid', $e->errorCode);
			}
		}
		$this->expectException(DomainException::class);
		try {
			$svc->verify('0000', null, 'tablet');
		} catch (DomainException $e) {
			self::assertSame('unlock_lockout', $e->errorCode);
			self::assertSame(30, $e->retryAfterSeconds);
			throw $e;
		}
	}
}
