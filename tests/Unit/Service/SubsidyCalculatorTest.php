<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\SubsidyCalculator;
use PHPUnit\Framework\TestCase;

final class SubsidyCalculatorTest extends TestCase
{
	public function testZeroAllowancePassthrough(): void
	{
		$r = SubsidyCalculator::forUser(1250, 0);
		self::assertSame(1250, $r['gross_cents']);
		self::assertSame(0, $r['subsidy_cents']);
		self::assertSame(1250, $r['deduct_cents']);
	}

	public function testSubsidyCapsAtGross(): void
	{
		$r = SubsidyCalculator::forUser(800, 2000);
		self::assertSame(800, $r['subsidy_cents']);
		self::assertSame(0, $r['deduct_cents']);
	}

	public function testSubsidyPartial(): void
	{
		$r = SubsidyCalculator::forUser(1500, 500);
		self::assertSame(500, $r['subsidy_cents']);
		self::assertSame(1000, $r['deduct_cents']);
	}

	public function testNegativeInputsClamped(): void
	{
		$r = SubsidyCalculator::forUser(-10, -5);
		self::assertSame(0, $r['gross_cents']);
		self::assertSame(0, $r['subsidy_cents']);
		self::assertSame(0, $r['deduct_cents']);
	}

	public function testLineTotalAndComplimentary(): void
	{
		self::assertSame(300, SubsidyCalculator::lineTotal(3, 100));
		self::assertTrue(SubsidyCalculator::isComplimentaryLine(0));
		self::assertFalse(SubsidyCalculator::isComplimentaryLine(1));
	}

	public function testQtyBounds(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SubsidyCalculator::lineTotal(0, 100);
	}

	public function testTopUpWhenBelowParWithPace(): void
	{
		$h = SubsidyCalculator::topUpHint(14, 7, 2, 10, 3);
		self::assertEqualsWithDelta(2.0, $h['avg_per_day'], 1e-9);
		self::assertEqualsWithDelta(1.0, $h['days_left'], 1e-9);
		self::assertTrue($h['top_up']);
	}

	public function testNoTopUpWithoutStockScalars(): void
	{
		$h = SubsidyCalculator::topUpHint(14, 7, null, null, 3);
		self::assertFalse($h['top_up']);
		self::assertNull($h['days_left']);
	}

	public function testTopUpWhenNoSalesButBelowPar(): void
	{
		$h = SubsidyCalculator::topUpHint(0, 0, 1, 5, 3);
		self::assertTrue($h['top_up']);
	}

	public function testSuggestedBuy(): void
	{
		self::assertSame(7, SubsidyCalculator::suggestedBuy(10, 3));
		self::assertSame(0, SubsidyCalculator::suggestedBuy(3, 10));
		self::assertSame(0, SubsidyCalculator::suggestedBuy(null, 3));
	}

	public function testFormatEur(): void
	{
		self::assertSame('12.50', SubsidyCalculator::formatEur(1250));
		self::assertSame('0.00', SubsidyCalculator::formatEur(0));
		self::assertSame('-1.05', SubsidyCalculator::formatEur(-105));
	}
}
