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
	}
}
