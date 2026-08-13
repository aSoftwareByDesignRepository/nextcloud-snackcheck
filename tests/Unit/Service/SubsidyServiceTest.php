<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\SubsidyService;
use PHPUnit\Framework\TestCase;

class SubsidyServiceTest extends TestCase
{
	private SubsidyService $svc;

	protected function setUp(): void
	{
		$this->svc = new SubsidyService();
	}

	public function testExactSubsidyMath(): void
	{
		$lines = [
			['line_total_cents' => 300, 'billing_bucket' => 'personal'],
			['line_total_cents' => 200, 'billing_bucket' => 'personal'],
			['line_total_cents' => 0, 'billing_bucket' => 'personal'], // free
			['line_total_cents' => 500, 'billing_bucket' => 'company_hospitality'],
		];
		$r = $this->svc->computeForUser(400, $lines);
		self::assertSame(500, $r['gross_cents']);
		self::assertSame(400, $r['subsidy_cents']);
		self::assertSame(100, $r['deduct_cents']);
	}

	public function testFreeNeverConsumesSubsidy(): void
	{
		$r = $this->svc->computeForUser(1000, [
			['line_total_cents' => 0, 'billing_bucket' => 'personal'],
			['line_total_cents' => 0, 'billing_bucket' => 'personal'],
		]);
		self::assertSame(0, $r['gross_cents']);
		self::assertSame(0, $r['subsidy_cents']);
		self::assertSame(0, $r['deduct_cents']);
	}

	public function testHospitalityExcludedFromPersonal(): void
	{
		$r = $this->svc->computeForUser(100, [
			['line_total_cents' => 250, 'billing_bucket' => 'company_hospitality'],
		]);
		self::assertSame(0, $r['gross_cents']);
	}

	public function testReconcileInvariant(): void
	{
		$lines = [
			['line_total_cents' => 100, 'billing_bucket' => 'personal'],
			['line_total_cents' => 200, 'billing_bucket' => 'personal'],
			['line_total_cents' => 50, 'billing_bucket' => 'company_hospitality'],
		];
		$s1 = $this->svc->computeForUser(150, $lines);
		self::assertTrue($this->svc->reconcileInvariant([$s1], $lines));
	}

	public function testLineTotalCents(): void
	{
		self::assertSame(250, SubsidyService::lineTotalCents(5, 50));
		$this->expectException(\InvalidArgumentException::class);
		SubsidyService::lineTotalCents(0, 50);
	}

	public function testAllowanceCappedAtGross(): void
	{
		$r = $this->svc->computeForUser(9999, [
			['line_total_cents' => 120, 'billing_bucket' => 'personal'],
		]);
		self::assertSame(120, $r['subsidy_cents']);
		self::assertSame(0, $r['deduct_cents']);
	}

	public function testVoidedIgnored(): void
	{
		$r = $this->svc->computeForUser(100, [
			['line_total_cents' => 80, 'billing_bucket' => 'personal', 'voided' => true],
			['line_total_cents' => 50, 'billing_bucket' => 'personal'],
		]);
		self::assertSame(50, $r['gross_cents']);
	}
}
