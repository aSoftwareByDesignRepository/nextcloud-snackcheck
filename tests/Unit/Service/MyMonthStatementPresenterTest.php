<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\MyMonthStatementPresenter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class MyMonthStatementPresenterTest extends TestCase
{
	public function testHidesSubsidyWhenAllowanceAndAmountAreZero(): void
	{
		$p = new MyMonthStatementPresenter();
		self::assertFalse($p->showSubsidy(0, 0));
		self::assertTrue($p->showSubsidy(500, 0));
		self::assertTrue($p->showSubsidy(0, 120));
	}

	public function testBreakdownOmitsSubsidyRowWhenNotApplicable(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$p = new MyMonthStatementPresenter();
		$rows = $p->breakdownRows($l, 120, 0, 0);
		self::assertCount(1, $rows);
		self::assertSame('What you logged', $rows[0]['label']);
		self::assertSame('1,20 €', $rows[0]['value']);
	}

	public function testPdfDocumentIncludesTotalLineAndLocalizedColumns(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);
		$p = new MyMonthStatementPresenter();
		$doc = $p->buildPdfDocument(
			$l,
			'2026-08',
			'Alex',
			'2026-08-28 10:00',
			[
				['name' => 'Coffee', 'qty' => 1, 'line_total_cents' => 50, 'free' => false, 'createdAt' => '2026-08-27 12:00'],
			],
			50,
			0,
			50,
			0,
			0,
			false,
		);
		self::assertSame('TOTAL TO DEDUCT', $doc['totalLine']['label']);
		self::assertSame('0.50 EUR', $doc['totalLine']['value']);
		self::assertSame('To deduct', $doc['keyFigure']['label']);
		self::assertCount(1, $doc['breakdown']);
		self::assertSame(['Item', 'Qty', 'Amount', 'When'], $doc['columns']);
	}
}
