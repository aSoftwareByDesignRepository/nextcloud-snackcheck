<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Kitchen safety + WCAG: hazard tags must stay on Log tiles and in the accessible name.
 */
final class LogAllergenTileContractTest extends TestCase
{
	public function testLogTilesPreferHazardTagsInVisibleAndAriaName(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/parts/snk-log-tile.php');
		self::assertStringContainsString("\$hazardOrder = ['contains_nuts', 'contains_alcohol']", $src);
		self::assertStringContainsString('$ariaParts', $src);
		self::assertStringContainsString("implode(' · ', \$ariaParts)", $src);
		// Regression: alcohol-only special-case that dropped nuts.
		self::assertStringNotContainsString("if (\$tag === 'contains_alcohol')", $src);
	}
}
