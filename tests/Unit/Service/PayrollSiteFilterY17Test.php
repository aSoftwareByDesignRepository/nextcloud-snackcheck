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
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SubsidyService;
use PHPUnit\Framework\TestCase;

final class PayrollSiteFilterY17Test extends TestCase
{
	public function testSummaryByUserSiteRespectsFilterWhileUserSummaryStaysOrgWide(): void
	{
		$period = new Period();
		$period->setId(9);
		$period->setLabel('2026-08');

		$berlin = $this->log(1, 'anna', 1200, 1);
		$munich = $this->log(2, 'anna', 800, 2);

		$logs = $this->createMock(ConsumptionLogMapper::class);
		$logs->method('findForPeriod')->willReturn([$berlin, $munich]);

		$sites = $this->createMock(SiteMapper::class);
		$s1 = new Site(); $s1->setId(1); $s1->setCode('BER'); $s1->setName('Berlin');
		$s2 = new Site(); $s2->setId(2); $s2->setCode('MUC'); $s2->setName('Munich');
		$sites->method('findAllActive')->willReturn([$s1, $s2]);

		$subsidy = $this->createMock(SubsidyService::class);
		$subsidy->method('computeForUser')->willReturnCallback(static function (int $allowance, array $lines): array {
			$gross = 0;
			foreach ($lines as $l) {
				$gross += (int)$l['line_total_cents'];
			}
			$sub = min($allowance, $gross);
			return ['gross_cents' => $gross, 'subsidy_cents' => $sub, 'deduct_cents' => $gross - $sub];
		});
		$subsidy->method('reconcileInvariant')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSubsidyAllowanceCents')->willReturn(500);
		$settings->method('isMultiSiteEnabled')->willReturn(true);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('get')->willReturn($period);

		$svc = new PayrollExportService($logs, $sites, $subsidy, $settings, $periods, $this->createMock(\OCP\IUserManager::class));
		$pkg = $svc->buildPersonalPackage(9, 1);

		self::assertCount(1, $pkg['lines']);
		self::assertSame(1, $pkg['lines'][0]['site_id']);
		self::assertCount(1, $pkg['summaryByUserSite']);
		self::assertSame('BER', $pkg['summaryByUserSite'][0]['site_code']);
		self::assertSame(1200, $pkg['summaryByUserSite'][0]['gross_cents']);
		// Org-wide summary includes Berlin+Munich gross before subsidy.
		self::assertSame(2000, $pkg['summaryByUser'][0]['gross_cents']);
		self::assertSame(1500, $pkg['summaryByUser'][0]['deduct_cents']);
	}

	private function log(int $id, string $uid, int $cents, int $siteId): ConsumptionLog
	{
		$log = new ConsumptionLog();
		$log->setId($id);
		$log->setUserId($uid);
		$log->setUserDisplaySnap($uid);
		$log->setItemNameSnap('Item');
		$log->setQty(1);
		$log->setUnitPriceCents($cents);
		$log->setLineTotalCents($cents);
		$log->setSiteId($siteId);
		$log->setBillingBucket('personal');
		$log->setSource('web');
		$log->setCreatedAt(new \DateTime('2026-08-10'));
		return $log;
	}
}
