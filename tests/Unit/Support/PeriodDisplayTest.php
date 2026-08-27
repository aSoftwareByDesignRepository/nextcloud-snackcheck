<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Support;

use OCA\SnackCheck\Support\PeriodDisplay;
use PHPUnit\Framework\TestCase;

final class PeriodDisplayTest extends TestCase
{
	public function testPlainMonthLabel(): void
	{
		self::assertSame('2026-08', PeriodDisplay::format('2026-08'));
		self::assertSame(['base' => '2026-08', 'suffix' => null], PeriodDisplay::parse('2026-08'));
	}

	public function testSuccessorDoesNotLookLikeACalendarDay(): void
	{
		self::assertSame('2026-08 (#35)', PeriodDisplay::format('2026-08-35'));
		self::assertSame(['base' => '2026-08', 'suffix' => 35], PeriodDisplay::parse('2026-08-35'));
		self::assertSame('2026-08 (#2)', PeriodDisplay::format('2026-08-2'));
	}

	public function testUnknownLabelsPassThrough(): void
	{
		self::assertSame('custom-label', PeriodDisplay::format('custom-label'));
	}
}
