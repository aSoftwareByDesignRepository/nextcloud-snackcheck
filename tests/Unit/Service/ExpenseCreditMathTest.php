<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\SubsidyService;
use PHPUnit\Framework\TestCase;

/**
 * NN-* expense credits math: negative ledger rows net against the deduction;
 * clamp mode reports the un-netted remainder instead of paying out.
 */
class ExpenseCreditMathTest extends TestCase
{
	private SubsidyService $svc;

	protected function setUp(): void
	{
		$this->svc = new SubsidyService();
	}

	public function testCreditsNetAgainstDeduction(): void
	{
		$r = $this->svc->computeForUser(0, [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => -200, 'billing_bucket' => 'personal_credit'],
		]);
		self::assertSame(500, $r['gross_cents']);
		self::assertSame(200, $r['credits_cents']);
		self::assertSame(300, $r['deduct_cents']);
		self::assertSame(0, $r['unused_credit_cents']);
	}

	public function testSubsidyAppliesBeforeCredits(): void
	{
		// G=500, A=200 → applied 200; credits 350 → deduct -50 → payout mode stays negative.
		$r = $this->svc->computeForUser(200, [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => -350, 'billing_bucket' => 'personal_credit'],
		]);
		self::assertSame(200, $r['subsidy_cents']);
		self::assertSame(-50, $r['deduct_cents']);
		self::assertSame(0, $r['unused_credit_cents']);
	}

	public function testClampModeFloorsAtZeroAndReportsRemainder(): void
	{
		$lines = [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => -350, 'billing_bucket' => 'personal_credit'],
		];
		$r = $this->svc->computeForUser(200, $lines, true);
		self::assertSame(0, $r['deduct_cents']);
		self::assertSame(50, $r['unused_credit_cents']);
	}

	public function testVoidedCreditIsIgnored(): void
	{
		$r = $this->svc->computeForUser(0, [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => -200, 'billing_bucket' => 'personal_credit', 'voided' => true],
		]);
		self::assertSame(0, $r['credits_cents']);
		self::assertSame(500, $r['deduct_cents']);
	}

	public function testHospitalityRowsNeverCountAsCreditOrGross(): void
	{
		$r = $this->svc->computeForUser(0, [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => 900, 'billing_bucket' => 'company_hospitality'],
			['line_total_cents' => -100, 'billing_bucket' => 'company_hospitality'],
		]);
		self::assertSame(500, $r['gross_cents']);
		self::assertSame(0, $r['credits_cents']);
		self::assertSame(500, $r['deduct_cents']);
	}

	public function testFreePersonalLinesDoNotConsumeCredit(): void
	{
		// Complimentary personal rows (price 0) must not add gross — and a
		// positive "credit" row (data bug) must never increase the credit pool.
		$r = $this->svc->computeForUser(0, [
			['line_total_cents' => 0, 'billing_bucket' => 'personal'],
			['line_total_cents' => 300, 'billing_bucket' => 'personal_credit'],
		]);
		self::assertSame(0, $r['credits_cents']);
		self::assertSame(0, $r['deduct_cents']);
	}

	public function testMissingBucketDefaultsToPersonal(): void
	{
		$r = $this->svc->computeForUser(0, [
			['line_total_cents' => 100],
			['line_total_cents' => -40, 'billing_bucket' => 'personal_credit'],
		]);
		self::assertSame(60, $r['deduct_cents']);
	}

	public function testReconcilePayoutMode(): void
	{
		$lines = [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => -700, 'billing_bucket' => 'personal_credit'],
		];
		$s = $this->svc->computeForUser(100, $lines, false);
		self::assertTrue($this->svc->reconcileInvariant([$s], $lines, false));
		// A tampered summary must fail reconciliation.
		$bad = $s;
		$bad['deduct_cents'] += 1;
		self::assertFalse($this->svc->reconcileInvariant([$bad], $lines, false));
	}

	public function testReconcileClampMode(): void
	{
		$lines = [
			['line_total_cents' => 500, 'billing_bucket' => 'personal'],
			['line_total_cents' => -700, 'billing_bucket' => 'personal_credit'],
		];
		$s = $this->svc->computeForUser(100, $lines, true);
		self::assertSame(0, $s['deduct_cents']);
		self::assertSame(300, $s['unused_credit_cents']);
		self::assertTrue($this->svc->reconcileInvariant([$s], $lines, true));
		// Clamp summaries must NOT reconcile under payout semantics.
		self::assertFalse($this->svc->reconcileInvariant([$s], $lines, false));
	}

	public function testReconcileAggregatesMultipleUsers(): void
	{
		$alice = [
			['line_total_cents' => 200, 'billing_bucket' => 'personal'],
			['line_total_cents' => -150, 'billing_bucket' => 'personal_credit'],
		];
		$bob = [
			['line_total_cents' => 900, 'billing_bucket' => 'personal'],
		];
		$sA = $this->svc->computeForUser(0, $alice, true);
		$sB = $this->svc->computeForUser(50, $bob, true);
		self::assertTrue($this->svc->reconcileInvariant([$sA, $sB], array_merge($alice, $bob), true));
	}
}
