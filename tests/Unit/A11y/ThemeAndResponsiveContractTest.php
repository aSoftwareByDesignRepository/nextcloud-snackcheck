<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\A11y;

use PHPUnit\Framework\TestCase;

/**
 * Theme + responsive contracts — NC variables under snk-* tokens (design-system SoT).
 */
final class ThemeAndResponsiveContractTest extends TestCase
{
	private function css(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
	}

	public function testMapsTokensToNextcloudColorVariables(): void
	{
		$css = $this->css();
		foreach ([
			'--color-main-background',
			'--color-main-text',
			'--color-text-maxcontrast',
			'--color-primary-element',
			'--color-primary-element-text',
			'--color-primary-element-hover',
			'--color-warning-text',
			'--color-error-text',
			'--color-element-error',
			'--color-success-text',
			'--border-radius-small',
			'--border-radius-large',
			'--header-height',
		] as $var) {
			self::assertStringContainsString($var, $css, "missing NC var $var");
		}
	}

	public function testTokenSplitRootStructuralBodyTheme(): void
	{
		$css = $this->css();
		// DS §1.1 — spacing/type/radius on :root; theme colours on body (nav + dialogs inherit).
		self::assertMatchesRegularExpression('/:root\s*\{[\s\S]*?--snk-space-1:/', $css);
		self::assertMatchesRegularExpression('/:root\s*\{[\s\S]*?--snk-radius-md:/', $css);
		self::assertMatchesRegularExpression('/^body\s*\{/m', $css);
		self::assertMatchesRegularExpression('/body\s*\{[\s\S]*?--snk-primary:/', $css);
		self::assertMatchesRegularExpression('/body\s*\{[\s\S]*?--snk-muted:/', $css);
		self::assertMatchesRegularExpression(
			'/body\[data-theme-dark\][\s\S]*?--snk-danger-fill:/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/body\[data-theme-light-highcontrast\][\s\S]*?--snk-border:/',
			$css
		);
		// Must not re-define theme colours only on #app-content (sidebar would miss them).
		self::assertDoesNotMatchRegularExpression(
			'/#app-content\.snk-app,\s*\n\.snk-app\s*\{[\s\S]*?--snk-primary:/',
			$css
		);
	}

	public function testNoHardcodedWarnBackdropHexOutsideVarFallbacks(): void
	{
		$css = $this->css();
		// Feature mixes must not use raw brand hex (legacy warn callout / black scrim).
		self::assertStringNotContainsString('#b54708 50%', $css);
		self::assertStringNotContainsString('#fff4e5', $css);
		self::assertStringNotContainsString('#000 40%', $css);
		self::assertStringContainsString('--snk-tint-warning', $css);
		self::assertStringContainsString('--snk-scrim', $css);
		self::assertStringContainsString('--snk-warning-ink', $css);
	}

	public function testDarkThemeDangerFillOverride(): void
	{
		$css = $this->css();
		self::assertStringContainsString('data-theme-dark', $css);
		self::assertStringContainsString('--snk-danger-on-fill: var(--color-main-text', $css);
	}

	public function testResponsiveBreakpointsAndSafeArea(): void
	{
		$css = $this->css();
		self::assertStringContainsString('@media (max-width: 768px)', $css);
		self::assertStringContainsString('@media (max-width: 480px)', $css);
		self::assertStringContainsString('safe-area-inset-bottom', $css);
		self::assertStringContainsString('safe-area-inset-left', $css);
		self::assertStringContainsString('overflow-x: clip', $css);
		self::assertStringContainsString('minmax(0, 1fr)', $css);
		self::assertMatchesRegularExpression(
			'/\.snk-page-header__main\s*\{[\s\S]*?grid-template-columns:\s*56px minmax\(0,\s*1fr\) auto/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.snk-page-header__text\s*\{[\s\S]*?min-width:\s*0/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.snk-page-header__text h1\s*\{[\s\S]*?overflow-wrap:\s*anywhere/',
			$css
		);
		self::assertStringContainsString('max-height: min(90dvh', $css);
		self::assertStringContainsString('@media (forced-colors: active)', $css);
		// Equal tracks: 2-up below 768; single column only on very narrow phones.
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 768px\)[\s\S]{0,900}\.snk-tile-grid\s*\{\s*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)\s*;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 480px\)[\s\S]{0,400}\.snk-tile-grid\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s*;/',
			$css
		);
		self::assertStringNotContainsString("stroke='%23595959'", $css);
		self::assertStringContainsString('--icon-triangle-s-dark', $css);
		self::assertStringContainsString('clip-path: inset(50%)', $css);
		self::assertStringNotContainsString('left: -9999px', $css);
	}

	public function testFocusContrastAndHighContrastMode(): void
	{
		$css = $this->css();
		self::assertStringContainsString('outline-offset: var(--snk-focus-offset)', $css);
		self::assertStringContainsString('@media (prefers-contrast: more)', $css);
		self::assertStringContainsString('body[data-theme-light-highcontrast]', $css);
		self::assertStringContainsString('body[data-theme-dark-highcontrast]', $css);
		self::assertStringContainsString('prefers-reduced-motion', $css);
		self::assertStringContainsString('min-height: var(--snk-touch)', $css);
		self::assertStringContainsString('--snk-touch-lg: 48px', $css);
		self::assertStringContainsString('.snk-select option', $css);
		// NC core buttons are ~34px — app rules must win via #app-content.snk-app specificity.
		self::assertStringContainsString('#app-content.snk-app .snk-btn', $css);
		self::assertStringContainsString('#app-content.snk-app .snk-btn--primary', $css);
		self::assertStringContainsString('#app-content.snk-app .snk-filter', $css);
		self::assertMatchesRegularExpression(
			'/\.snk-nav__link\.is-active \.snk-nav__hint[\s\S]{0,80}color:\s*var\(--snk-text\)/',
			$css
		);
		self::assertStringContainsString(':focus:not(:focus-visible)', $css);
		self::assertStringContainsString('.snk-mode-chip:has(input:focus-visible)', $css);
		self::assertStringContainsString('border-inline-start-width: 5px', $css);
		self::assertStringContainsString('.snk-mode-chip', $css);
		self::assertStringContainsString('.snk-term-list__row', $css);
		self::assertStringContainsString('--snk-icon-stroke', $css);
	}

	public function testSpacingAndRadiusScalePresent(): void
	{
		$css = $this->css();
		self::assertStringContainsString('--snk-space-8: 64px', $css);
		// NC-linked sm/lg + fixed Check-family md (12px) so md never collapses onto lg.
		self::assertStringContainsString('--snk-radius-sm: var(--border-radius-small, 6px)', $css);
		self::assertStringContainsString('--snk-radius-md: 12px', $css);
		self::assertStringContainsString('--snk-radius-lg: var(--border-radius-large, 16px)', $css);
		self::assertStringContainsString('--snk-radius-pill: 999px', $css);
		self::assertStringContainsString('--snk-product-stage:', $css);
		self::assertMatchesRegularExpression(
			'/\.snk-tile__media\s*\{[^}]*background:\s*var\(--snk-product-stage\)/',
			$css
		);
		self::assertStringNotContainsString('background: #ffffff', $css);
		self::assertStringNotContainsString('background: #fff', $css);
	}
}
