<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\ShoppingListHtmlBuilder;
use PHPUnit\Framework\TestCase;

final class ShoppingListHtmlBuilderTest extends TestCase
{
	public function testBuildsPrintFriendlyTableAndEscapesHtml(): void
	{
		$html = ShoppingListHtmlBuilder::build([
			[
				'name' => 'Cola <script>',
				'category' => 'drink',
				'onHand' => 1,
				'parLevel' => 5,
				'suggestedBuy' => 4,
				'complimentary' => false,
			],
		], 'Buy list');
		self::assertStringContainsString('<!DOCTYPE html>', $html);
		// CSP pins: no inline handler/button — the print affordance is a hint.
		self::assertStringContainsString('print-hint', $html);
		self::assertStringContainsString('Ctrl+P', $html);
		self::assertStringNotContainsString('window.print()', $html);
		self::assertStringNotContainsString('onclick=', $html);
		self::assertStringNotContainsString('<button', $html);
		self::assertStringContainsString('Cola &lt;script&gt;', $html);
		self::assertStringNotContainsString('<script>', $html);
		self::assertStringContainsString('<th scope="col">Item</th>', $html);
		self::assertStringContainsString('>4</td>', $html);
		self::assertStringContainsString('color-scheme:light dark', $html);
		self::assertStringContainsString('CanvasText', $html);
		self::assertStringContainsString('@media print', $html);
		self::assertStringContainsString('.print-hint{display:none;}', $html);
	}

	public function testEmptyListHasHonestEmptyState(): void
	{
		$html = ShoppingListHtmlBuilder::build([]);
		self::assertStringContainsString('Nothing to buy right now.', $html);
		self::assertStringNotContainsString('<tbody>', $html);
	}
}
