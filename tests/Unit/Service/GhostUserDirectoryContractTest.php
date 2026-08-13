<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Db\UnlockPinMapper;
use OCA\SnackCheck\Db\UnlockQrMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * API/service trust boundary: directory pickers are UI-only.
 * Ghost UIDs must never create ledger rows, PIN maps, or hospitality charges.
 */
final class GhostUserDirectoryContractTest extends TestCase
{
	private function userManagerAllowing(array $uids): IUserManager
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($uids) {
			if (!in_array($uid, $uids, true)) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		return $users;
	}

	private function logService(IUserManager $users, AccessControlService $access, SettingsService $settings, HospAllowMapper $hosp): ConsumptionLogService
	{
		$item = new CatalogItem();
		$item->setId(1);
		$item->setActive(1);
		$item->setSiteId(1);
		$item->setPriceCents(100);
		$item->setName('Cola');
		$catalog = $this->createMock(CatalogService::class);
		$catalog->method('getForUpdate')->willReturn($item);
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn(null);
		$period = new Period();
		$period->setId(9);
		$period->setState('open');
		$period->setLabel('2026-08');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		return new ConsumptionLogService(
			$mapper,
			$periodMapper,
			$catalog,
			$periods,
			$settings,
			$this->createMock(AuditService::class),
			$hosp,
			$access,
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$users,
		);
	}

	public function testProxyGhostTargetRejectedEvenWhenAccessDoorOpen(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->willReturn(true);
		$svc = $this->logService(
			$this->userManagerAllowing(['manager']),
			$access,
			$this->createMock(SettingsService::class),
			$this->createMock(HospAllowMapper::class),
		);
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 1,
				'qty' => 1,
				'idempotencyKey' => 'ghost-proxy-1',
				'siteId' => 1,
				'actorUserId' => 'manager',
				'source' => 'web',
				'mode' => 'proxy',
				'targetUserId' => 'not-a-real-user',
				'proxyReason' => 'covering lunch',
				'isKitchenAdmin' => true,
			]);
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
			throw $e;
		}
	}

	public function testHospitalityRejectsMissingCompanyUserAccount(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isHospitalityEnabled')->willReturn(true);
		$settings->method('getHospitalityCompanyUserId')->willReturn('ghost-company');
		$hosp = $this->createMock(HospAllowMapper::class);
		$hosp->method('isAllowed')->willReturn(true);
		$svc = $this->logService(
			$this->userManagerAllowing(['alice']),
			$this->createMock(AccessControlService::class),
			$settings,
			$hosp,
		);
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 1,
				'qty' => 1,
				'idempotencyKey' => 'ghost-hosp-1',
				'siteId' => 1,
				'actorUserId' => 'alice',
				'source' => 'web',
				'mode' => 'hospitality',
				'hospitalityReason' => 'visitor coffee',
			]);
		} catch (DomainException $e) {
			self::assertSame('hospitality_disabled', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
			throw $e;
		}
	}

	public function testUnlockPinRejectsGhostUser(): void
	{
		$cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$svc = new UnlockService(
			$this->createMock(UnlockPinMapper::class),
			$this->createMock(UnlockQrMapper::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(HospAllowMapper::class),
			$this->userManagerAllowing(['admin']),
			$this->createMock(ITimeFactory::class),
			$this->createMock(ISecureRandom::class),
			$cacheFactory,
			$this->createMock(ILockingProvider::class),
		);
		$this->expectException(DomainException::class);
		try {
			$svc->setPin('ghost-user', '1234', 'admin');
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
			throw $e;
		}
	}

	public function testUnlockQrRejectsGhostUser(): void
	{
		$cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$svc = new UnlockService(
			$this->createMock(UnlockPinMapper::class),
			$this->createMock(UnlockQrMapper::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(HospAllowMapper::class),
			$this->userManagerAllowing(['admin']),
			$this->createMock(ITimeFactory::class),
			$this->createMock(ISecureRandom::class),
			$cacheFactory,
			$this->createMock(ILockingProvider::class),
		);
		$this->expectException(DomainException::class);
		try {
			$svc->setQr('ghost-user', 'payload-ok', 'admin');
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			throw $e;
		}
	}

	public function testServiceSourceRequiresUserManagerGetOnProxyAndHospitality(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ConsumptionLogService.php');
		self::assertMatchesRegularExpression(
			'/mode === \'proxy\'[\s\S]{0,500}userManager->get\(\$target\)/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/getHospitalityCompanyUserId\(\)[\s\S]{0,200}userManager->get\(\$company\)/',
			$src
		);
		$unlock = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/UnlockService.php');
		self::assertMatchesRegularExpression(
			'/function setPin[\s\S]{0,200}userManager->get\(\$userId\)/',
			$unlock
		);
		self::assertMatchesRegularExpression(
			'/function setQr[\s\S]{0,200}userManager->get\(\$userId\)/',
			$unlock
		);
		$api = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('function requireExistingUsers(', $api);
		self::assertStringContainsString('function requireExistingGroups(', $api);
		self::assertStringContainsString('requireExistingUsers($allowList)', $api);
	}
}
