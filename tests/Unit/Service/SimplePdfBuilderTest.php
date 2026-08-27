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

	public function testStatementIncludesTotalToDeductAndSummary(): void
	{
		$pdf = SimplePdfBuilder::buildStatement([
			'brand' => 'SnackCheck',
			'title' => 'My month',
			'meta' => [
				['Period', '2026-08'],
				['Person', 'Alex'],
			],
			'summary' => [
				['label' => 'Gross', 'value' => '1.20 EUR'],
				['label' => 'Subsidy', 'value' => '0.00 EUR'],
				['label' => 'Total to deduct', 'value' => '1.20 EUR', 'strong' => true],
			],
			'tableTitle' => 'Logged items',
			'columns' => ['Item', 'Qty', 'Amount'],
			'colWidths' => [0.6, 0.15, 0.25],
			'rows' => [
				['Coffee', '2', '1.20 EUR'],
				['Water', '1', 'Free'],
			],
			'totals' => [
				['label' => 'Gross', 'value' => '1.20 EUR'],
				['label' => 'Subsidy', 'value' => '0.00 EUR'],
				['label' => 'TOTAL TO DEDUCT', 'value' => '1.20 EUR', 'strong' => true],
			],
			'note' => 'Amounts in EUR.',
		]);
		self::assertStringStartsWith('%PDF-1.4', $pdf);
		self::assertStringContainsString('TOTAL TO DEDUCT', $pdf);
		self::assertStringContainsString('Total to deduct', $pdf);
		self::assertStringContainsString('1.20 EUR', $pdf);
		self::assertStringContainsString('Coffee', $pdf);
		self::assertStringContainsString('Helvetica-Bold', $pdf);
		self::assertStringContainsString('Page 1 of 1', $pdf);
	}

	public function testEmptyRowsStillEmitTotalBlock(): void
	{
		$pdf = SimplePdfBuilder::buildStatement([
			'title' => 'My month',
			'rows' => [],
			'totals' => [
				['label' => 'TOTAL TO DEDUCT', 'value' => '0.00 EUR', 'strong' => true],
			],
		]);
		self::assertStringContainsString('No items in this period.', $pdf);
		self::assertStringContainsString('TOTAL TO DEDUCT', $pdf);
		self::assertStringContainsString('0.00 EUR', $pdf);
	}

	public function testLongTablesPaginateInsteadOfTruncating(): void
	{
		$rows = [];
		for ($i = 1; $i <= 80; $i++) {
			$rows[] = ['Item ' . $i, (string)$i, number_format($i / 100, 2, '.', '') . ' EUR'];
		}
		$pdf = SimplePdfBuilder::buildStatement([
			'title' => 'My month',
			'columns' => ['Item', 'Qty', 'Amount'],
			'colWidths' => [0.6, 0.15, 0.25],
			'rows' => $rows,
			'totals' => [
				['label' => 'TOTAL TO DEDUCT', 'value' => '32.40 EUR', 'strong' => true],
			],
		]);
		self::assertMatchesRegularExpression('/\/Count [2-9]/', $pdf);
		self::assertStringContainsString('Page 1 of ', $pdf);
		self::assertStringContainsString('continued', $pdf);
		self::assertStringContainsString('Item 1', $pdf);
		self::assertStringContainsString('Item 80', $pdf);
		self::assertStringContainsString('TOTAL TO DEDUCT', $pdf);
	}
}
