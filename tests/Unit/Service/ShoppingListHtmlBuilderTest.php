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
		self::assertStringContainsString('window.print()', $html);
		self::assertStringContainsString('Cola &lt;script&gt;', $html);
		self::assertStringNotContainsString('<script>', $html);
		self::assertStringContainsString('<th scope="col">Item</th>', $html);
		self::assertStringContainsString('>4</td>', $html);
		self::assertStringContainsString('color-scheme:light dark', $html);
		self::assertStringContainsString('CanvasText', $html);
		self::assertStringContainsString('min-height:44px', $html);
	}

	public function testEmptyListHasHonestEmptyState(): void
	{
		$html = ShoppingListHtmlBuilder::build([]);
		self::assertStringContainsString('Nothing to buy right now.', $html);
		self::assertStringNotContainsString('<tbody>', $html);
	}
}
