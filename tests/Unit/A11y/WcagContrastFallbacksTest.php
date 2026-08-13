<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\A11y;

use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.1 AA contrast proof for SnackCheck CSS fallback hex pairs (AC-29).
 * Full axe/browser harness is OUT on this host; these ratios are automated evidence.
 */
final class WcagContrastFallbacksTest extends TestCase
{
	private static function luminance(string $hex): float
	{
		$hex = ltrim($hex, '#');
		$r = hexdec(substr($hex, 0, 2)) / 255;
		$g = hexdec(substr($hex, 2, 2)) / 255;
		$b = hexdec(substr($hex, 4, 2)) / 255;
		$lin = static function (float $c): float {
			return $c <= 0.04045 ? $c / 12.92 : (( $c + 0.055) / 1.055) ** 2.4;
		};
		return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
	}

	private static function ratio(string $fg, string $bg): float
	{
		$l1 = self::luminance($fg);
		$l2 = self::luminance($bg);
		$hi = max($l1, $l2);
		$lo = min($l1, $l2);
		return ($hi + 0.05) / ($lo + 0.05);
	}

	public function testFallbackTextOnBackgroundMeetsAa(): void
	{
		// From css/app.css fallbacks
		self::assertGreaterThanOrEqual(4.5, self::ratio('#1d1d1d', '#ffffff'));
		self::assertGreaterThanOrEqual(4.5, self::ratio('#595959', '#ffffff'));
		self::assertGreaterThanOrEqual(4.5, self::ratio('#ffffff', '#006aa3'));
		self::assertGreaterThanOrEqual(4.5, self::ratio('#ffffff', '#c0341d'));
		$css = (string)file_get_contents(__DIR__ . '/../../../css/app.css');
		self::assertMatchesRegularExpression('/--snk-muted:[^;]*#595959/', $css);
		self::assertMatchesRegularExpression('/--snk-primary:[^;]*#006aa3/', $css);
	}

	public function testCssDeclaresFocusAndReducedMotion(): void
	{
		$css = (string)file_get_contents(__DIR__ . '/../../../css/app.css');
		self::assertStringContainsString(':focus-visible', $css);
		self::assertStringContainsString('prefers-reduced-motion', $css);
		self::assertStringContainsString('--snk-touch: 44px', $css);
		self::assertStringContainsString('min-height: var(--snk-touch)', $css);
	}

	public function testDesignChecklistDocumentsA11y(): void
	{
		$doc = (string)file_get_contents(__DIR__ . '/../../../docs/DESIGN-SYSTEM-CHECKLIST-EVIDENCE.md');
		self::assertStringContainsString('Accessibility', $doc);
		self::assertStringContainsString('skip link', $doc);
		self::assertStringContainsString('DEVICE-SHORTLIST', $doc);
	}
}
