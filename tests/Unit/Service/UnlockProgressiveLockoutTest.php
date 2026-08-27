<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\UnlockPin;
use OCA\SnackCheck\Db\UnlockPinMapper;
use OCA\SnackCheck\Db\UnlockQrMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/** Aristoteles / Argus SF-02 — progressive unlock lockout + accurate retryAfter. */
final class UnlockProgressiveLockoutTest extends TestCase
{
	/** @var array<string,mixed> */
	private array $store = [];

	private function cache(): ICache
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) {
			return $this->store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, $value) {
			$this->store[$key] = $value;
			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key) {
			unset($this->store[$key]);
			return true;
		});
		return $cache;
	}

	private function service(?UnlockPinMapper $pins = null, ?AccessControlService $access = null): UnlockService
	{
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache());
		$locking = $this->createMock(ILockingProvider::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUnlockPepper')->willReturn('test-pepper-32-characters-long!!');
		$settings->method('isHospitalityEnabled')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('c', 48));
		$access ??= $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->willReturn(true);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('isKitchenManager')->willReturn(false);
		return new UnlockService(
			$pins ?? $this->createMock(UnlockPinMapper::class),
			$this->createMock(UnlockQrMapper::class),
			$access,
			$settings,
			$this->createMock(HospAllowMapper::class),
			$users,
			$time,
			$random,
			$factory,
			$locking,
		);
	}

	private function tripLockout(UnlockService $svc): DomainException
	{
		for ($i = 0; $i < 2; $i++) {
			try {
				$svc->verify('0000', null, 'tablet');
				self::fail('expected unlock_invalid');
			} catch (DomainException $e) {
				self::assertSame('unlock_invalid', $e->errorCode);
			}
		}
		try {
			$svc->verify('0000', null, 'tablet');
			self::fail('expected unlock_lockout');
		} catch (DomainException $e) {
			self::assertSame('unlock_lockout', $e->errorCode);
			return $e;
		}
	}

	public function testFirstTripLocksThirtySecondsWithRetryAfter(): void
	{
		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->willReturn(null);
		$svc = $this->service($pins);
		$e = $this->tripLockout($svc);
		self::assertSame(30, $e->retryAfterSeconds);
		self::assertSame(1_700_000_030, $this->store['lockout:tablet']);
		self::assertSame(1, $this->store['tier:tablet']);
	}

	public function testSecondTripEscalatesToSixtySeconds(): void
	{
		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->willReturn(null);
		$svc = $this->service($pins);
		$this->tripLockout($svc);
		unset($this->store['lockout:tablet']); // lock expired
		$e = $this->tripLockout($svc);
		self::assertSame(60, $e->retryAfterSeconds);
		self::assertSame(2, $this->store['tier:tablet']);
	}

	public function testSuccessfulUnlockClearsEscalationTier(): void
	{
		$hash = hash_hmac('sha256', '1234', 'snk-pin|test-pepper-32-characters-long!!');
		$row = new UnlockPin();
		$row->setUserId('alice');
		$row->setPinHash($hash);
		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->willReturnCallback(static function (string $h) use ($hash, $row) {
			return hash_equals($hash, $h) ? $row : null;
		});
		$svc = $this->service($pins);
		$this->tripLockout($svc);
		unset($this->store['lockout:tablet']);
		self::assertSame(1, $this->store['tier:tablet']);
		$result = $svc->verify('1234', null, 'tablet', null, null, 'dev-1');
		self::assertStringStartsWith('snkunlock_', $result['unlockToken']);
		self::assertArrayNotHasKey('tier:tablet', $this->store);
		self::assertArrayNotHasKey('fails:tablet', $this->store);

		// Next trip after success is first-tier again (30s).
		$pinsBad = $this->createMock(UnlockPinMapper::class);
		$pinsBad->method('findByPinHash')->willReturn(null);
		$svc2 = $this->service($pinsBad);
		// Reuse store via same cache mock — new service needs same store; rebuild with tier already cleared.
		$e = $this->tripLockout($svc2);
		self::assertSame(30, $e->retryAfterSeconds);
	}

	public function testActiveLockReportsRemainingRetryAfter(): void
	{
		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->willReturn(null);
		$svc = $this->service($pins);
		$this->store['lockout:tablet'] = 1_700_000_000 + 17;
		try {
			$svc->verify('0000', null, 'tablet');
			self::fail('expected lockout');
		} catch (DomainException $e) {
			self::assertSame('unlock_lockout', $e->errorCode);
			self::assertSame(17, $e->retryAfterSeconds);
		}
	}

	public function testFailCounterTtlOutlivesMaxLockoutStep(): void
	{
		self::assertGreaterThanOrEqual(
			2 * UnlockService::LOCKOUT_SCHEDULE_SECONDS[count(UnlockService::LOCKOUT_SCHEDULE_SECONDS) - 1],
			UnlockService::FAIL_COUNTER_TTL_SECONDS
		);
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/UnlockService.php');
		self::assertStringContainsString('FAIL_COUNTER_TTL_SECONDS', $src);
		self::assertStringNotContainsString('self::LOCKOUT_SECONDS * 2', $src);
		self::assertStringContainsString('withDeviceFailLock', $src);
	}
}
