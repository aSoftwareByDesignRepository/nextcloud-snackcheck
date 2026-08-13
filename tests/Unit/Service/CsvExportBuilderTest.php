<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\CsvExportBuilder;
use PHPUnit\Framework\TestCase;

final class CsvExportBuilderTest extends TestCase
{
	public function testBomAndDelimiter(): void
	{
		$csv = CsvExportBuilder::build(['a', 'b'], [['1', 'x;y'], ['2', 'ok']]);
		self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
		self::assertStringContainsString("a;b\n", $csv);
		self::assertStringContainsString('"x;y"', $csv);
	}

	public function testNeutralizesFormulaInjection(): void
	{
		self::assertSame("'=1+1", CsvExportBuilder::neutralizeFormula('=1+1'));
		self::assertSame("'+cmd", CsvExportBuilder::neutralizeFormula('+cmd'));
		self::assertSame("'-2", CsvExportBuilder::neutralizeFormula('-2'));
		self::assertSame("'@SUM", CsvExportBuilder::neutralizeFormula('@SUM'));
		self::assertSame('plain', CsvExportBuilder::neutralizeFormula('plain'));
		$csv = CsvExportBuilder::build(['n'], [['=HYPERLINK("x")']]);
		self::assertStringContainsString("'=HYPERLINK", $csv);
	}
}
