<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Schema reason columns are 500 chars — reject overlong before DB 500. */
final class ReasonMaxLengthContractTest extends TestCase
{
	private function service(
		SettingsService $settings,
		HospAllowMapper $hosp,
		AccessControlService $access,
		?IUserManager $users = null,
	): ConsumptionLogService {
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

		$userManager = $users ?? $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(function (string $uid) {
			if ($uid === '') {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($uid);
			return $user;
		});

		$period = new Period();
		$period->setId(1);
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
			$userManager,
		);
	}

	public function testHospitalityReasonOver500Rejected(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isHospitalityEnabled')->willReturn(true);
		$settings->method('getHospitalityCompanyUserId')->willReturn('company');
		$hosp = $this->createMock(HospAllowMapper::class);
		$hosp->method('isAllowed')->willReturn(true);

		$svc = $this->service($settings, $hosp, $this->createMock(AccessControlService::class));
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 1,
				'qty' => 1,
				'idempotencyKey' => 'rmax-h',
				'siteId' => 1,
				'actorUserId' => 'alice',
				'source' => 'web',
				'mode' => 'hospitality',
				'hospitalityReason' => str_repeat('x', 501),
			]);
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
			throw $e;
		}
	}

	public function testProxyReasonOver500Rejected(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->willReturn(true);
		$svc = $this->service(
			$this->createMock(SettingsService::class),
			$this->createMock(HospAllowMapper::class),
			$access,
		);
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 1,
				'qty' => 1,
				'idempotencyKey' => 'rmax-p',
				'siteId' => 1,
				'actorUserId' => 'manager',
				'source' => 'web',
				'mode' => 'proxy',
				'targetUserId' => 'bob',
				'proxyReason' => str_repeat('y', 501),
				'isKitchenAdmin' => true,
			]);
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			throw $e;
		}
	}
}
