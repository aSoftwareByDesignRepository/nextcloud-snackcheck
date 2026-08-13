<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\SiteMapper;
use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SubsidyService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** AC-OPP-X4/X5 / NN-17: hospitality export columns, void exclusion, site filter. */
final class HospitalityExportContractTest extends TestCase
{
	public function testBuildHospitalityRowsSkipsNonHospitalityAndVoidedAndFiltersSite(): void
	{
		$period = new Period();
		$period->setId(3);
		$period->setLabel('2026-08');

		$keep = new ConsumptionLog();
		$keep->setId(1);
		$keep->setBillingBucket('company_hospitality');
		$keep->setSiteId(2);
		$keep->setUserId('company');
		$keep->setLoggedBy('alice');
		$keep->setItemNameSnap('Cola');
		$keep->setQty(1);
		$keep->setUnitPriceCents(0);
		$keep->setLineTotalCents(0);
		$keep->setHospReason('Guest visit');
		$keep->setSource('hospitality_terminal');
		$keep->setCreatedAt(new \DateTime('2026-08-10T10:00:00+00:00'));

		$wrongSite = clone $keep;
		$wrongSite->setId(2);
		$wrongSite->setSiteId(9);

		$personal = clone $keep;
		$personal->setId(3);
		$personal->setBillingBucket('personal');

		$logs = $this->createMock(ConsumptionLogMapper::class);
		$logs->method('findForPeriod')->with(3, false)->willReturn([$keep, $wrongSite, $personal]);

		$siteA = new Site();
		$siteA->setId(2);
		$siteA->setCode('BER');
		$siteA->setName('Berlin');
		$sites = $this->createMock(SiteMapper::class);
		$sites->method('findAllActive')->willReturn([$siteA]);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('get')->with(3)->willReturn($period);

		$svc = new PayrollExportService(
			$logs,
			$sites,
			new SubsidyService(),
			$this->createMock(\OCA\SnackCheck\Service\SettingsService::class),
			$periods,
			$this->createMock(IUserManager::class),
		);

		$rowsAll = $svc->buildHospitalityRows(3, null);
		self::assertCount(2, $rowsAll);

		$rowsBer = $svc->buildHospitalityRows(3, 2);
		self::assertCount(1, $rowsBer);
		self::assertSame('BER', $rowsBer[0]['site_code']);
		self::assertSame('Cola', $rowsBer[0]['item_name']);
		self::assertSame(0, $rowsBer[0]['line_total_cents']);
		self::assertSame('Guest visit', $rowsBer[0]['reason']);
		self::assertArrayHasKey('actor_uid', $rowsBer[0]);
		self::assertArrayHasKey('company_user_id', $rowsBer[0]);
		self::assertArrayHasKey('period_label', $rowsBer[0]);
	}

	public function testHospitalityCsvEscapesFormulas(): void
	{
		$svc = new PayrollExportService(
			$this->createMock(ConsumptionLogMapper::class),
			$this->createMock(SiteMapper::class),
			new SubsidyService(),
			$this->createMock(\OCA\SnackCheck\Service\SettingsService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(IUserManager::class),
		);
		$csv = $svc->toCsv([
			[
				'item_name' => '=CMD()',
				'qty' => '1',
			],
		], ['item_name', 'qty']);
		self::assertStringContainsString("'=CMD()", $csv);
	}
}
