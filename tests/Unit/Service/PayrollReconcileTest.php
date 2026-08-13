<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\PayrollReconcile;
use PHPUnit\Framework\TestCase;

final class PayrollReconcileTest extends TestCase
{
	public function testSummarizeExcludesFreeVoidedAndHospitality(): void
	{
		$lines = [
			['user_id' => 'a', 'line_total_cents' => 200, 'billing_bucket' => 'personal', 'voided' => false],
			['user_id' => 'a', 'line_total_cents' => 0, 'billing_bucket' => 'personal', 'voided' => false],
			['user_id' => 'a', 'line_total_cents' => 100, 'billing_bucket' => 'personal', 'voided' => true],
			['user_id' => 'b', 'line_total_cents' => 500, 'billing_bucket' => 'company_hospitality', 'voided' => false],
			['user_id' => 'b', 'line_total_cents' => 300, 'billing_bucket' => 'personal', 'voided' => false],
		];
		$sum = PayrollReconcile::summarizeByUser($lines, 100);
		self::assertCount(2, $sum);
		self::assertSame('a', $sum[0]['user_id']);
		self::assertSame(200, $sum[0]['gross_cents']);
		self::assertSame(100, $sum[0]['subsidy_cents']);
		self::assertSame(100, $sum[0]['deduct_cents']);
		self::assertSame('b', $sum[1]['user_id']);
		self::assertSame(300, $sum[1]['gross_cents']);
		self::assertSame(100, $sum[1]['subsidy_cents']);
		self::assertSame(200, $sum[1]['deduct_cents']);

		PayrollReconcile::assertInvariant($lines, $sum, 100);
	}

	public function testInvariantDetectsTamperedDeduct(): void
	{
		$lines = [
			['user_id' => 'a', 'line_total_cents' => 200, 'billing_bucket' => 'personal', 'voided' => false],
		];
		$sum = [['user_id' => 'a', 'gross_cents' => 200, 'subsidy_cents' => 0, 'deduct_cents' => 199]];
		$this->expectException(\RuntimeException::class);
		PayrollReconcile::assertInvariant($lines, $sum, 0);
	}
}
