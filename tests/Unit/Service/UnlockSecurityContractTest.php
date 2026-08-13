<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\UnlockPinMapper;
use OCA\SnackCheck\Db\UnlockQr;
use OCA\SnackCheck\Db\UnlockQrMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
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

/** Argus MF: QR/NFC pepper + unlock token device binding. */
final class UnlockSecurityContractTest extends TestCase
{
	private function pepper(): string
	{
		return 'fixed-pepper-value-32chars-min!!';
	}

	private function hashQr(string $payload): string
	{
		return hash_hmac('sha256', $payload, 'snk-qr|' . $this->pepper());
	}

	private function service(
		UnlockQrMapper $qrs,
		ICache $cache,
		?UnlockPinMapper $pins = null,
	): UnlockService {
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$access->method('isKitchenManager')->willReturn(false);
		$access->method('canAccessApp')->willReturn(true);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUnlockPepper')->willReturn($this->pepper());
		$settings->method('isHospitalityEnabled')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$time->method('getDateTime')->willReturn(new \DateTime('@1700000000'));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('b', 48));
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		return new UnlockService(
			$pins ?? $this->createMock(UnlockPinMapper::class),
			$qrs,
			$access,
			$settings,
			$this->createMock(HospAllowMapper::class),
			$users,
			$time,
			$random,
			$factory,
			$this->createMock(ILockingProvider::class),
		);
	}

	public function testQrLookupUsesPepperedHmacNotBareSha256(): void
	{
		$qrRow = new UnlockQr();
		$qrRow->setUserId('alice');
		$qrs = $this->createMock(UnlockQrMapper::class);
		$qrs->expects($this->once())->method('findByTokenHash')
			->with($this->hashQr('badge-1'))
			->willReturn($qrRow);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->once())->method('set')->with(
			$this->stringStartsWith('tok:'),
			$this->callback(static function (array $session): bool {
				return ($session['deviceId'] ?? null) === '42';
			}),
			UnlockService::TOKEN_TTL_SECONDS
		);
		$svc = $this->service($qrs, $cache);
		$result = $svc->verify(null, null, 'dev:42:127.0.0.1', 'badge-1', 1, '42');
		self::assertSame('alice', $result['userId']);
	}

	public function testPeekRejectsTokenFromOtherDevice(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn([
			'userId' => 'alice',
			'expiresAt' => 1_700_000_100,
			'isKitchenAdmin' => false,
			'hospitalityAllowed' => false,
			'deviceId' => '7',
		]);
		$svc = $this->service($this->createMock(UnlockQrMapper::class), $cache);
		try {
			$svc->peekUnlockToken('snkunlock_' . str_repeat('c', 48), '99');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('unlock_invalid', $e->errorCode);
			self::assertSame(401, $e->httpStatus);
		}
	}

	public function testPeekAcceptsBoundDevice(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn([
			'userId' => 'alice',
			'expiresAt' => 1_700_000_100,
			'isKitchenAdmin' => true,
			'hospitalityAllowed' => false,
			'deviceId' => '7',
		]);
		$svc = $this->service($this->createMock(UnlockQrMapper::class), $cache);
		$session = $svc->peekUnlockToken('snkunlock_ok', '7');
		self::assertSame('alice', $session['userId']);
		self::assertTrue($session['isKitchenAdmin']);
	}

	public function testInvalidateRequiresBoundDeviceWhenProvided(): void
	{
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn([
			'userId' => 'alice',
			'expiresAt' => 1_700_000_100,
			'isKitchenAdmin' => false,
			'hospitalityAllowed' => false,
			'deviceId' => '7',
		]);
		$cache->expects($this->never())->method('remove');
		$svc = $this->service($this->createMock(UnlockQrMapper::class), $cache);
		try {
			$svc->invalidateUnlockToken('snkunlock_x', '99');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('unlock_invalid', $e->errorCode);
		}
	}

	public function testInvalidateRemovesWhenDeviceMatches(): void
	{
		$token = 'snkunlock_' . str_repeat('d', 48);
		$key = 'tok:' . hash('sha256', $token);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn([
			'userId' => 'alice',
			'expiresAt' => 1_700_000_100,
			'isKitchenAdmin' => false,
			'hospitalityAllowed' => false,
			'deviceId' => '7',
		]);
		$cache->expects($this->once())->method('remove')->with($key);
		$svc = $this->service($this->createMock(UnlockQrMapper::class), $cache);
		$svc->invalidateUnlockToken($token, '7');
	}

	public function testShortQrPayloadRejectedOnSet(): void
	{
		$svc = $this->service($this->createMock(UnlockQrMapper::class), $this->createMock(ICache::class));
		try {
			$svc->setQr('alice', 'ab', 'admin');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
		}
	}
}
