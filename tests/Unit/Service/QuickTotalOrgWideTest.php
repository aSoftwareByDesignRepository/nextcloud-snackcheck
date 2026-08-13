<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Quick-total must match org-wide My month (MH-23), not fridge-only. */
final class QuickTotalOrgWideTest extends TestCase
{
	public function testSumsPersonalChargeableAcrossAllSites(): void
	{
		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$berlin = new ConsumptionLog();
		$berlin->setSiteId(10);
		$berlin->setBillingBucket('personal');
		$berlin->setLineTotalCents(150);

		$munich = new ConsumptionLog();
		$munich->setSiteId(20);
		$munich->setBillingBucket('personal');
		$munich->setLineTotalCents(80);

		$hosp = new ConsumptionLog();
		$hosp->setSiteId(10);
		$hosp->setBillingBucket('company_hospitality');
		$hosp->setLineTotalCents(999);

		$free = new ConsumptionLog();
		$free->setSiteId(20);
		$free->setBillingBucket('personal');
		$free->setLineTotalCents(0);

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForUserPeriod')->with(1, 'alice')->willReturn([$berlin, $munich, $hosp, $free]);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);

		$svc = new ConsumptionLogService(
			$mapper,
			$this->createMock(PeriodMapper::class),
			$this->createMock(CatalogService::class),
			$periods,
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
		);

		// Even when device site is Berlin (10), Munich personal cents must count.
		self::assertSame(230, $svc->quickTotalCentsForUser('alice', 10));
		self::assertSame(230, $svc->quickTotalCentsForUser('alice', 0));
	}
}
