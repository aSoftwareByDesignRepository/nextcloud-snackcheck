<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\UnlockPinMapper;
use OCA\SnackCheck\Db\UnlockQr;
use OCA\SnackCheck\Db\UnlockQrMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

final class UnlockNfcAliasTest extends TestCase
{
	public function testNfcPayloadUsesQrMap(): void
	{
		$qrRow = new UnlockQr();
		$qrRow->setUserId('alice');
		$qrs = $this->createMock(UnlockQrMapper::class);
		$qrs->method('findByTokenHash')->willReturnCallback(static function (string $hash) use ($qrRow) {
			$pepper = 'fixed-pepper-value-32chars-min!!';
			$expected = hash_hmac('sha256', 'badge-1', 'snk-qr|' . $pepper);
			return hash_equals($expected, $hash) ? $qrRow : null;
		});
		$pins = $this->createMock(UnlockPinMapper::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('isKitchenManager')->willReturn(false);
		$access->method('canAccessApp')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUnlockPepper')->willReturn('fixed-pepper-value-32chars-min!!');
		$settings->method('isHospitalityEnabled')->willReturn(false);
		$hosp = $this->createMock(HospAllowMapper::class);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('a', 48));
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$svc = new UnlockService(
			$pins,
			$qrs,
			$access,
			$settings,
			$hosp,
			$users,
			$time,
			$random,
			$factory,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
		$result = $svc->verify(null, null, 'dev:1', 'badge-1', null, '1');
		self::assertSame('alice', $result['userId']);
		self::assertStringStartsWith('snkunlock_', $result['unlockToken']);
	}
}
