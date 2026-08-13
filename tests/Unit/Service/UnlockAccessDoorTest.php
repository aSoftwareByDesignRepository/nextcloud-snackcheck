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
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

final class UnlockAccessDoorTest extends TestCase
{
	private function service(AccessControlService $access, UnlockPinMapper $pins): UnlockService
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUnlockPepper')->willReturn('test-pepper-32-characters-long!!');
		$settings->method('isHospitalityEnabled')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('b', 48));
		return new UnlockService(
			$pins,
			$this->createMock(UnlockQrMapper::class),
			$access,
			$settings,
			$this->createMock(HospAllowMapper::class),
			$users,
			$time,
			$random,
			$factory,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
	}

	public function testListedAclBlocksUnlockEvenWithValidPin(): void
	{
		$hash = hash_hmac('sha256', '1234', 'snk-pin|test-pepper-32-characters-long!!');
		$row = new UnlockPin();
		$row->setUserId('alice');
		$row->setPinHash($hash);

		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->with($hash)->willReturn($row);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->with('alice')->willReturn(false);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('isKitchenManager')->willReturn(false);

		$svc = $this->service($access, $pins);
		$this->expectException(DomainException::class);
		try {
			$svc->verify('1234', null, 'dev:1', null);
		} catch (DomainException $e) {
			// Argus: ACL deny must not oracle valid PIN via 403 vs 401.
			self::assertSame('unlock_invalid', $e->errorCode);
			self::assertSame(401, $e->httpStatus);
			throw $e;
		}
	}

	public function testAccessAllowedIssuesToken(): void
	{
		$hash = hash_hmac('sha256', '1234', 'snk-pin|test-pepper-32-characters-long!!');
		$row = new UnlockPin();
		$row->setUserId('alice');
		$row->setPinHash($hash);

		$pins = $this->createMock(UnlockPinMapper::class);
		$pins->method('findByPinHash')->willReturn($row);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->with('alice')->willReturn(true);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('isKitchenManager')->willReturn(false);

		$svc = $this->service($access, $pins);
		$result = $svc->verify('1234', null, 'dev:1', null);
		self::assertSame('alice', $result['userId']);
		self::assertStringStartsWith('snkunlock_', $result['unlockToken']);
	}
}
