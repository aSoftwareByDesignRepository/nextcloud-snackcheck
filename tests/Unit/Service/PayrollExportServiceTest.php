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
		foreach (['=cmd', '+cmd', '-2', '@SUM', "\t=x", "\r=x", "\x0B=x"] as $hostile) {
			$escaped = PayrollExportService::csvEscape($hostile);
			self::assertStringContainsString(
				"'" . $hostile,
				$escaped,
				'hostile first char must be apostrophe-prefixed: ' . bin2hex($hostile[0])
			);
		}
		// CR/LF-bearing cells must be quoted so the control char cannot forge a row.
		self::assertSame("\"a\rb\"", PayrollExportService::csvEscape("a\rb"));
		self::assertSame("\"'\r=x\"", PayrollExportService::csvEscape("\r=x"));
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

	private function logRow(array $over): \OCA\SnackCheck\Db\ConsumptionLog
	{
		$log = new \OCA\SnackCheck\Db\ConsumptionLog();
		$base = [
			'Id' => 1,
			'UserId' => 'alice',
			'UserDisplaySnap' => 'Alice A.',
			'ItemNameSnap' => 'Coffee',
			'Qty' => 1,
			'UnitPriceCents' => 150,
			'LineTotalCents' => 150,
			'BillingBucket' => 'personal',
			'SiteId' => 1,
			'Source' => 'web',
			'CreatedAt' => new \DateTime('2026-08-10T12:00:00+00:00'),
		];
		foreach (array_merge($base, $over) as $k => $v) {
			$log->{'set' . $k}($v);
		}
		return $log;
	}

	private function packageFor(array $logs, bool $clamp = false, ?int $siteFilter = null): array
	{
		$mapper = $this->createMock(\OCA\SnackCheck\Db\ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->with(9, false)->willReturn($logs);

		$site = new \OCA\SnackCheck\Db\Site();
		$site->setId(1);
		$site->setCode('HK');
		$site->setName('Hauptküche');
		$sites = $this->createMock(\OCA\SnackCheck\Db\SiteMapper::class);
		$sites->method('findAllActive')->willReturn([$site]);

		$period = new \OCA\SnackCheck\Db\Period();
		$period->setId(9);
		$period->setLabel('2026-08');
		$periods = $this->createMock(\OCA\SnackCheck\Service\PeriodService::class);
		$periods->method('get')->with(9)->willReturn($period);

		$settings = $this->createMock(\OCA\SnackCheck\Service\SettingsService::class);
		$settings->method('getSubsidyAllowanceCents')->willReturn(0);
		$settings->method('isExpenseClampNegativeDeduct')->willReturn($clamp);

		$svc = new PayrollExportService(
			$mapper, $sites, new SubsidyService(), $settings, $periods,
			$this->createMock(\OCP\IUserManager::class),
		);
		return $svc->buildPersonalPackage(9, $siteFilter);
	}

	public function testCreditLineCarriesEntryTypeAndReason(): void
	{
		$pkg = $this->packageFor([
			$this->logRow(['LineTotalCents' => 300, 'UnitPriceCents' => 150, 'Qty' => 2]),
			$this->logRow([
				'Id' => 2,
				'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -250,
				'UnitPriceCents' => -250,
				'BillingBucket' => 'personal_credit',
				'Source' => 'expense_web',
				'ExpenseReason' => 'Milk for the kitchen',
			]),
		]);

		self::assertCount(2, $pkg['lines']);
		$credit = $pkg['lines'][1];
		self::assertSame('expense_credit', $credit['entry_type']);
		self::assertSame('Milk for the kitchen', $credit['reason']);
		self::assertSame('-2.50', $credit['line_total_eur']);
		self::assertSame('consumption', $pkg['lines'][0]['entry_type']);
		self::assertSame('', $pkg['lines'][0]['reason']);

		$sum = $pkg['summaryByUser'][0];
		self::assertSame(300, $sum['gross_cents']);
		self::assertSame(250, $sum['credits_cents']);
		self::assertSame(50, $sum['deduct_cents']);
		self::assertSame(0, $sum['unused_credit_cents']);
		self::assertTrue($pkg['reconcileOk']);
	}

	public function testCreditExceedsGrossKeepsNegativeDeduct(): void
	{
		// NN-08: payout mode never clamps — the org owes the member.
		$pkg = $this->packageFor([
			$this->logRow(['LineTotalCents' => 100, 'UnitPriceCents' => 100]),
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -400, 'UnitPriceCents' => -400,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
				'ExpenseReason' => 'Beans',
			]),
		]);
		$sum = $pkg['summaryByUser'][0];
		self::assertSame(-300, $sum['deduct_cents']);
		self::assertSame(400, $sum['credits_cents']);
		self::assertTrue($pkg['reconcileOk']);
	}

	public function testClampModeReportsUnusedCredit(): void
	{
		$pkg = $this->packageFor([
			$this->logRow(['LineTotalCents' => 100, 'UnitPriceCents' => 100]),
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -400, 'UnitPriceCents' => -400,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
				'ExpenseReason' => 'Beans',
			]),
		], clamp: true);
		$sum = $pkg['summaryByUser'][0];
		self::assertSame(0, $sum['deduct_cents']);
		self::assertSame(300, $sum['unused_credit_cents']);
		self::assertTrue($pkg['reconcileOk']);
	}

	public function testCreditNeverCountsAsComplimentary(): void
	{
		// NN-02: complimentary = only zero-total personal rows.
		$pkg = $this->packageFor([
			$this->logRow(['LineTotalCents' => 0, 'UnitPriceCents' => 0, 'Qty' => 3]),
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -100, 'UnitPriceCents' => -100,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
				'ExpenseReason' => 'Tea',
			]),
		]);
		self::assertSame(3, $pkg['complimentaryQtyExcluded']);
		self::assertCount(1, $pkg['lines']);
		self::assertSame('expense_credit', $pkg['lines'][0]['entry_type']);
	}

	public function testMalformedPositiveCreditRowIsSkipped(): void
	{
		// NN-10: a personal_credit row that is not negative is malformed —
		// it must not reach lines, summary, or complimentary stats.
		$pkg = $this->packageFor([
			$this->logRow(['LineTotalCents' => 200, 'UnitPriceCents' => 200]),
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => 100, 'UnitPriceCents' => 100,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
			]),
		]);
		self::assertCount(1, $pkg['lines']);
		self::assertSame('consumption', $pkg['lines'][0]['entry_type']);
		$sum = $pkg['summaryByUser'][0];
		self::assertSame(200, $sum['gross_cents']);
		self::assertSame(0, $sum['credits_cents']);
		self::assertTrue($pkg['reconcileOk']);
	}

	public function testCreditNotInSiteGrossSplit(): void
	{
		// summary_by_user_site stays consumption-gross only (spec: credits org-level).
		$pkg = $this->packageFor([
			$this->logRow(['LineTotalCents' => 200, 'UnitPriceCents' => 200]),
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -90, 'UnitPriceCents' => -90,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
				'ExpenseReason' => 'Tea',
			]),
		]);
		self::assertSame(200, $pkg['summaryByUserSite'][0]['gross_cents']);
	}

	public function testCreditsBySiteSheet(): void
	{
		$pkg = $this->packageFor([
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -90, 'UnitPriceCents' => -90,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
				'ExpenseReason' => 'Tea',
			]),
			$this->logRow([
				'Id' => 3, 'ItemNameSnap' => 'Expense',
				'LineTotalCents' => -60, 'UnitPriceCents' => -60,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_admin',
				'ExpenseReason' => 'Coffee',
			]),
		]);
		self::assertCount(1, $pkg['creditsBySite']);
		self::assertSame('HK', $pkg['creditsBySite'][0]['site_code']);
		self::assertSame(150, $pkg['creditsBySite'][0]['credits_cents']);
		// Site-filtered export scopes the sheet the same way as lines.
		$filtered = $this->packageFor([
			$this->logRow([
				'Id' => 2, 'ItemNameSnap' => 'Expense', 'SiteId' => 2,
				'LineTotalCents' => -90, 'UnitPriceCents' => -90,
				'BillingBucket' => 'personal_credit', 'Source' => 'expense_web',
			]),
		], siteFilter: 1);
		self::assertSame([], $filtered['creditsBySite']);
	}

	public function testCsvColumnOrderKeepsAppendedFieldsLast(): void
	{
		// AC-G2: entry_type + reason appended AFTER site_name — positional parsers survive.
		$svc = new PayrollExportService(
			$this->createMock(\OCA\SnackCheck\Db\ConsumptionLogMapper::class),
			$this->createMock(\OCA\SnackCheck\Db\SiteMapper::class),
			new SubsidyService(),
			$this->createMock(\OCA\SnackCheck\Service\SettingsService::class),
			$this->createMock(\OCA\SnackCheck\Service\PeriodService::class),
			$this->createMock(\OCP\IUserManager::class),
		);
		$csv = $svc->toCsv(
			[['site_name' => 'HK', 'entry_type' => 'expense_credit', 'reason' => "=cmd|'/C calc'!A0"]],
			['site_name', 'entry_type', 'reason'],
		);
		$head = explode("\n", $csv)[1] ?? '';
		self::assertStringContainsString('site_name;entry_type;reason', $csv);
		// Formula-injection: hostile reason is apostrophe-escaped.
		self::assertStringContainsString("'=cmd|'/C calc'!A0", $csv);
	}
}
