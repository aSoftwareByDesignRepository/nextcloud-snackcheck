<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\SubsidyService;
use PHPUnit\Framework\TestCase;

class PayrollExportServiceTest extends TestCase
{
	public function testCsvHasBomAndSemicolon(): void
	{
		$logs = $this->createMock(\OCA\SnackCheck\Db\ConsumptionLogMapper::class);
		$sites = $this->createMock(\OCA\SnackCheck\Db\SiteMapper::class);
		$svc = new PayrollExportService(
			$logs, $sites, new SubsidyService(),
			$this->createMock(\OCA\SnackCheck\Service\SettingsService::class),
			$this->createMock(\OCA\SnackCheck\Service\PeriodService::class),
			$this->createMock(\OCP\IUserManager::class),
		);
		$csv = $svc->toCsv([
			['user_id' => 'alice', 'item_name' => 'Coffee', 'qty' => 1],
		], ['user_id', 'item_name', 'qty']);
		self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
		self::assertStringContainsString('user_id;item_name;qty', $csv);
		self::assertSame('1.50', PayrollExportService::centsToEur(150));
		self::assertStringStartsWith("'", PayrollExportService::csvEscape('=cmd'));
	}

	public function testXlsxIsZip(): void
	{
		$svc = new PayrollExportService(
			$this->createMock(\OCA\SnackCheck\Db\ConsumptionLogMapper::class),
			$this->createMock(\OCA\SnackCheck\Db\SiteMapper::class),
			new SubsidyService(),
			$this->createMock(\OCA\SnackCheck\Service\SettingsService::class),
			$this->createMock(\OCA\SnackCheck\Service\PeriodService::class),
			$this->createMock(\OCP\IUserManager::class),
		);
		$bin = $svc->toXlsx([
			'lines' => [['period_label' => '2026-08', 'user_id' => 'u', 'user_display_name' => 'U', 'item_name' => 'Tea', 'qty' => 1, 'unit_price_eur' => '0.40', 'line_total_eur' => '0.40', 'logged_at' => '', 'source' => 'web', 'site_code' => 'DEFAULT', 'site_name' => 'Default']],
			'summaryByUser' => [['user_id' => 'u', 'user_display_name' => 'U', 'gross_cents' => 40, 'subsidy_cents' => 0, 'deduct_cents' => 40]],
			'summaryByUserSite' => [['user_id' => 'u', 'site_code' => 'DEFAULT', 'site_name' => 'Default', 'gross_cents' => 40]],
		]);
		self::assertSame('PK', substr($bin, 0, 2));
	}
}
