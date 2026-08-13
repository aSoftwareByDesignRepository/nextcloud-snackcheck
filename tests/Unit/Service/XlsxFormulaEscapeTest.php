<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\XlsxWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class XlsxFormulaEscapeTest extends TestCase
{
	public function testInlineCellNeutralizesFormulaPrefixes(): void
	{
		$m = new ReflectionMethod(XlsxWriter::class, 'inlineCell');
		$m->setAccessible(true);
		$xml = $m->invoke(null, 'A1', '=1+1');
		self::assertStringContainsString("'=1+1", $xml);
		$xml2 = $m->invoke(null, 'B1', '+cmd');
		self::assertStringContainsString("'+cmd", $xml2);
		$plain = $m->invoke(null, 'C1', 'Cola');
		self::assertStringContainsString('Cola', $plain);
		self::assertStringNotContainsString("'Cola", $plain);
	}
}
