<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\SimplePdfBuilder;
use PHPUnit\Framework\TestCase;

final class SimplePdfBuilderTest extends TestCase
{
	public function testProducesPdfHeaderAndEscapesParens(): void
	{
		$pdf = SimplePdfBuilder::fromLines('Title (test)', ['Line (1)', 'Line 2']);
		self::assertStringStartsWith('%PDF-1.4', $pdf);
		self::assertStringContainsString('%%EOF', $pdf);
		self::assertStringContainsString('\\(test\\)', $pdf);
		self::assertStringContainsString('\\(1\\)', $pdf);
		self::assertStringContainsString('/Count 1', $pdf);
	}

	public function testStatementUsesKeyFigureBreakdownAndTotalLine(): void
	{
		$pdf = SimplePdfBuilder::buildStatement([
			'brand' => 'SnackCheck',
			'title' => 'My month',
			'meta' => [
				['Period', '2026-08'],
				['Person', 'Alex'],
			],
			'keyFigure' => [
				'label' => 'To deduct',
				'value' => '1.20 EUR',
			],
			'breakdown' => [
				['label' => 'What you logged', 'value' => '1.20 EUR'],
			],
			'totalLine' => [
				'label' => 'TOTAL TO DEDUCT',
				'value' => '1.20 EUR',
			],
			'tableTitle' => 'Logged items',
			'columns' => ['Item', 'Qty', 'Amount', 'When'],
			'colWidths' => [0.38, 0.08, 0.18, 0.36],
			'rows' => [
				['Coffee', '2', '1.20 EUR', '2026-08-27 15:50'],
				['Water', '1', 'Free', '2026-08-27 15:48'],
			],
			'note' => 'Amounts in EUR.',
		]);
		self::assertStringStartsWith('%PDF-1.4', $pdf);
		self::assertStringContainsString('To deduct', $pdf);
		self::assertStringContainsString('What you logged', $pdf);
		self::assertStringContainsString('TOTAL TO DEDUCT', $pdf);
		self::assertStringContainsString('1.20 EUR', $pdf);
		self::assertStringContainsString('Coffee', $pdf);
		self::assertStringContainsString('When', $pdf);
		self::assertStringNotContainsString('Summary', $pdf);
		self::assertStringContainsString('Helvetica-Bold', $pdf);
		self::assertStringContainsString('Page 1 of 1', $pdf);
	}

	public function testLegacySummaryAndTotalsStillRender(): void
	{
		$pdf = SimplePdfBuilder::buildStatement([
			'title' => 'Legacy',
			'summary' => [
				['label' => 'Gross', 'value' => '1.00 EUR'],
			],
			'totals' => [
				['label' => 'TOTAL TO DEDUCT', 'value' => '1.00 EUR', 'strong' => true],
			],
			'rows' => [],
		]);
		self::assertStringContainsString('Summary', $pdf);
		self::assertStringContainsString('TOTAL TO DEDUCT', $pdf);
	}

	public function testEmptyRowsStillEmitKeyFigure(): void
	{
		$pdf = SimplePdfBuilder::buildStatement([
			'title' => 'My month',
			'keyFigure' => [
				'label' => 'To deduct',
				'value' => '0.00 EUR',
			],
			'breakdown' => [
				['label' => 'What you logged', 'value' => '0.00 EUR'],
			],
			'totalLine' => [
				'label' => 'TOTAL TO DEDUCT',
				'value' => '0.00 EUR',
			],
			'emptyItemsText' => 'No items in this period.',
			'rows' => [],
		]);
		self::assertStringContainsString('No items in this period.', $pdf);
		self::assertStringContainsString('To deduct', $pdf);
		self::assertStringContainsString('0.00 EUR', $pdf);
	}

	public function testLongTablesPaginateInsteadOfTruncating(): void
	{
		$rows = [];
		for ($i = 1; $i <= 80; $i++) {
			$rows[] = ['Item ' . $i, (string)$i, number_format($i / 100, 2, '.', '') . ' EUR', '2026-08-27 12:00'];
		}
		$pdf = SimplePdfBuilder::buildStatement([
			'title' => 'My month',
			'keyFigure' => [
				'label' => 'To deduct',
				'value' => '32.40 EUR',
			],
			'breakdown' => [
				['label' => 'What you logged', 'value' => '32.40 EUR'],
			],
			'totalLine' => [
				'label' => 'TOTAL TO DEDUCT',
				'value' => '32.40 EUR',
			],
			'columns' => ['Item', 'Qty', 'Amount', 'When'],
			'colWidths' => [0.38, 0.08, 0.18, 0.36],
			'rows' => $rows,
		]);
		self::assertMatchesRegularExpression('/\/Count [2-9]/', $pdf);
		self::assertStringContainsString('Page 1 of ', $pdf);
		self::assertStringContainsString('continued', $pdf);
		self::assertStringContainsString('Item 1', $pdf);
		self::assertStringContainsString('Item 80', $pdf);
		self::assertStringContainsString('TOTAL TO DEDUCT', $pdf);
	}
}
