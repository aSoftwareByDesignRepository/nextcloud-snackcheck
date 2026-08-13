<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class UnlockPepperTest extends TestCase
{
	public function testPepperIsStableOnceGenerated(): void
	{
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default) use (&$store) {
			return $store[$key] ?? $default;
		});
		$config->method('setAppValue')->willReturnCallback(static function ($app, $key, $value) use (&$store): void {
			$store[$key] = $value;
		});
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with('snackcheck/unlock_pepper', ILockingProvider::LOCK_EXCLUSIVE);
		$locking->expects($this->once())->method('releaseLock')
			->with('snackcheck/unlock_pepper', ILockingProvider::LOCK_EXCLUSIVE);

		$svc = new SettingsService($config, $locking);
		$a = $svc->getUnlockPepper();
		$b = $svc->getUnlockPepper();
		self::assertSame($a, $b);
		self::assertGreaterThanOrEqual(32, strlen($a));
	}

	public function testPepperChangesHmacVsUnpeppered(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('fixed-pepper-value-32chars-min!!');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');
		$svc = new SettingsService($config, $locking);
		$peppered = hash_hmac('sha256', '1234', 'snk-pin|' . $svc->getUnlockPepper());
		$legacy = hash('sha256', 'snk-pin|1234');
		self::assertNotSame($legacy, $peppered);
	}

	public function testPepperMintBusyFailsClosedWhenStillEmpty(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new LockedException('snackcheck/unlock_pepper'));
		$svc = new SettingsService($config, $locking);
		try {
			$svc->getUnlockPepper();
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('unlock_busy', $e->errorCode);
			self::assertSame(429, $e->httpStatus);
		}
	}
}
