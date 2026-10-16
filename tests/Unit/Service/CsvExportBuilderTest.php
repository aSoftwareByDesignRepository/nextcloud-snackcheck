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
		foreach (['=1+1', '+cmd', '-2', '@SUM', "\t=x", "\r=x", "\x0B=x"] as $hostile) {
			self::assertSame(
				"'" . $hostile,
				CsvExportBuilder::neutralizeFormula($hostile),
				'hostile first char must be apostrophe-prefixed: ' . bin2hex($hostile[0])
			);
		}
		self::assertSame('plain', CsvExportBuilder::neutralizeFormula('plain'));
		$csv = CsvExportBuilder::build(['n'], [['=HYPERLINK("x")'], ["\x0B=x"]]);
		self::assertStringContainsString("'=HYPERLINK", $csv);
		self::assertStringContainsString("'\x0B=x", $csv);
	}
}
