<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/** Design-system + Check-family app icon contracts. */
final class AppIconAndDesignSystemChromeContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testAppIconIsWhiteStrokeFridgeLikeSiblings(): void
	{
		$svg = (string)file_get_contents($this->root() . '/img/app.svg');
		self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
		self::assertStringContainsString('stroke="#ffffff"', $svg);
		self::assertStringContainsString('aria-hidden="true"', $svg);
		self::assertStringNotContainsString('#2f6f4e', $svg);
		self::assertStringNotContainsString('role="img"', $svg);
		self::assertMatchesRegularExpression('/<rect[^>]+rx="2"/', $svg);
		self::assertFileExists($this->root() . '/img/app-dark.svg');
		$dark = (string)file_get_contents($this->root() . '/img/app-dark.svg');
		self::assertStringContainsString('stroke="#000000"', $dark);
	}

	public function testMainChromeHasLiveRegionsLangAndAriaCurrent(): void
	{
		$main = (string)file_get_contents($this->root() . '/templates/main.php');
		$nav = (string)file_get_contents($this->root() . '/templates/common/navigation.php');
		self::assertStringContainsString('id="snk-live-region"', $main);
		self::assertStringContainsString('id="snk-alert-region"', $main);
		self::assertStringContainsString('aria-live="assertive"', $main);
		self::assertStringContainsString('lang="<?php p($htmlLang); ?>"', $main);
		self::assertStringContainsString('data-snk-locale', $main);
		self::assertStringContainsString('id="app-navigation"', $nav);
		self::assertStringContainsString('aria-current="page"', $nav);
		self::assertStringContainsString('snk-brand__icon', $nav);
		self::assertStringContainsString('snk-page-header__icon', $main);
		self::assertStringContainsString('id="snk-page-title"', $main);
		self::assertStringContainsString('id="app-content-wrapper"', $main);
		self::assertStringContainsString('id="snk-toast"', $main);
	}

	public function testCssMatchesDesignSystemChromeBasics(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('background: var(--snk-primary)', $css);
		self::assertStringContainsString('.snk-badge::before', $css);
		self::assertStringContainsString('border-inline-start-width: 5px', $css);
		self::assertStringContainsString('max-width: none', $css);
		self::assertStringNotContainsString('max-width: 72rem', $css);
		self::assertStringContainsString('#app-navigation.snk-nav', $css);
		self::assertStringContainsString('.snk-page-header__icon', $css);
		self::assertStringContainsString('.snk-scope-strip', $css);
		self::assertStringContainsString('dialog.snk-dialog:not([open])', $css);
		self::assertStringContainsString('display: none !important', $css);
		self::assertStringContainsString('.snk-btn--danger', $css);
		self::assertStringContainsString('.snk-btn--ghost', $css);
		self::assertStringContainsString('.snk-switch-field', $css);
		self::assertStringContainsString('appearance: none', $css);
		// AZC/TC parity: soft bg, flex shell stack, 3px focus, empty icon well, page stack
		self::assertStringContainsString('--snk-bg-soft:', $css);
		self::assertStringContainsString('--snk-focus: 3px solid', $css);
		self::assertStringContainsString('flex-direction: column', $css);
		self::assertStringContainsString('.snk-page-stack', $css);
		self::assertStringContainsString('.snk-empty__icon', $css);
		self::assertStringContainsString('.snk-card__header', $css);
		self::assertStringContainsString('.snk-card--table-solo', $css);
		self::assertStringContainsString('--snk-nav-width-compact', $css);
		self::assertStringContainsString('--snk-surface-muted:', $css);
		self::assertStringContainsString('body[data-theme-dark]', $css);
		self::assertStringContainsString('body[data-theme-light-highcontrast]', $css);
		self::assertStringContainsString('body[data-theme-dark-highcontrast]', $css);
		self::assertStringContainsString('.snk-select option', $css);
		self::assertStringContainsString('--snk-radius-md: 12px', $css);
		self::assertStringContainsString('--border-radius-large', $css);
		self::assertStringContainsString('--color-background-dark', $css);
		self::assertStringContainsString('@media (forced-colors: active)', $css);
		self::assertMatchesRegularExpression('/^:root\s*\{/m', $css);
		self::assertMatchesRegularExpression('/^body\s*\{/m', $css);
		// Disabled chrome must not rely on washed-out opacity alone (WCAG 1.4.3)
		self::assertDoesNotMatchRegularExpression(
			'/\.snk-btn:disabled[^{]*\{[^}]*opacity:\s*0\.[0-6]/',
			$css
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.snk-tile\.is-logging[^{]*\{[^}]*opacity:\s*0\./',
			$css
		);
		self::assertStringContainsString('color-mix(in srgb, var(--color-main-background', $css);
		self::assertStringNotContainsString('background: #fff', $css);
		self::assertStringNotContainsString('color: #000', $css);
	}

	public function testEmptyStatePartialExists(): void
	{
		$path = $this->root() . '/templates/parts/snk-empty-state.php';
		self::assertFileExists($path);
		$src = (string)file_get_contents($path);
		self::assertStringContainsString('snk-empty__icon', $src);
		self::assertStringContainsString('IconCatalog::render', $src);
		self::assertStringContainsString('<h3 class="snk-empty__title">', $src);
		self::assertStringNotContainsString('<p class="snk-empty__title">', $src);
	}

	public function testFamilyEmptyAndFilterChrome(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		// Nested empties inside cards must not double-frame (AZC/CRM principle 14).
		self::assertStringContainsString('.snk-card__body > .snk-empty', $css);
		self::assertMatchesRegularExpression(
			'/\.snk-card__body > \.snk-empty[\s\S]{0,220}border:\s*none/',
			$css
		);
		// Empty root uses main text ink; only __text stays muted.
		self::assertMatchesRegularExpression(
			'/\.snk-empty\s*\{[^}]*color:\s*var\(--snk-text\)/',
			$css
		);
		// Category filters are pills, not a left-accent callout panel.
		self::assertStringContainsString('border-radius: var(--snk-radius-pill)', $css);
		self::assertDoesNotMatchRegularExpression(
			'/\.snk-filter-bar\s*\{[^}]*border-left:\s*4px/',
			$css
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.snk-empty\s*\{[^}]*color:\s*var\(--snk-muted\)/',
			$css
		);
		// §3.6c: filter-panel accent + quick-pills strip + embedded mode (no box-in-box).
		self::assertStringContainsString('.snk-filter-panel', $css);
		self::assertStringContainsString('border-inline-start: 4px solid var(--snk-primary)', $css);
		self::assertStringContainsString('.snk-quick-filters', $css);
		self::assertStringContainsString('.snk-mode-panel--embedded', $css);
		self::assertStringContainsString('.snk-mode-panel[hidden]', $css);
		self::assertStringContainsString('display: none !important', $css);
		$dsPath = dirname($this->root(), 3) . '/planning/design-system/DESIGN-SYSTEM.md';
		if (!is_file($dsPath)) {
			// Docker mounts only nextcloud/; planning/ lives on the host workspace.
			$this->markTestSkipped('DESIGN-SYSTEM.md not available in this environment');
		}
		$ds = (string)file_get_contents($dsPath);
		self::assertStringContainsString('### 3.6c Three filter / context recipes', $ds);
	}

	public function testToastAnnouncesToLiveRegions(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('function announce(', $js);
		self::assertStringContainsString('snk-alert-region', $js);
		self::assertStringContainsString('toast(userFacingError(e), null, true)', $js);
	}

	public function testUsesNextcloudNavToggleNotCustomBurger(): void
	{
		$main = (string)file_get_contents($this->root() . '/templates/main.php');
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringNotContainsString('snk-nav-toggle', $main);
		self::assertStringNotContainsString('data-snk-nav-toggle', $main);
		self::assertStringNotContainsString('initNavToggle', $js);
		self::assertStringNotContainsString('snk-nav--open', $js);
		self::assertDoesNotMatchRegularExpression(
			'/#app-navigation-toggle[^{]*\{[^}]*display:\s*none\s*!important/',
			$css
		);
		self::assertStringNotContainsString('snk-nav-toggle', $css);
	}

	public function testSiteScopeLabelNotBoundToNonLabelableSpan(): void
	{
		$main = (string)file_get_contents($this->root() . '/templates/main.php');
		self::assertStringContainsString('id="snk-site-scope-label"', $main);
		self::assertStringContainsString('aria-labelledby="snk-site-scope-label"', $main);
	}

	public function testAppStoreListingAssetsPresent(): void
	{
		$root = $this->root();
		self::assertFileExists($root . '/Makefile');
		self::assertFileExists($root . '/SECURITY.md');
		self::assertFileExists($root . '/LICENSE');
		$info = (string)file_get_contents($root . '/appinfo/info.xml');
		self::assertStringContainsString('<category>organization</category>', $info);
		self::assertStringContainsString('<category>tools</category>', $info);
		self::assertStringNotContainsString('<category>productivity</category>', $info);
		self::assertStringContainsString('nextcloud-snackcheck', $info);
		$shots = [
			'snackcheck-screenshot-01-log.png',
			'snackcheck-screenshot-02-my-month.png',
			'snackcheck-screenshot-03-catalog.png',
			'snackcheck-screenshot-04-pulse.png',
			'snackcheck-screenshot-05-periods.png',
			'snackcheck-screenshot-06-users.png',
			'snackcheck-screenshot-07-settings.png',
		];
		foreach ($shots as $name) {
			$path = $root . '/screenshots/' . $name;
			self::assertFileExists($path, $name);
			self::assertGreaterThan(20_000, filesize($path), $name);
			self::assertStringContainsString(
				'raw.githubusercontent.com/aSoftwareByDesignRepository/nextcloud-snackcheck/refs/heads/main/screenshots/' . $name,
				$info
			);
		}
		self::assertFileExists($root . '/e2e/capture-store-screenshots.spec.js');
		$pw = (string)file_get_contents($root . '/playwright.config.js');
		self::assertStringContainsString("name: 'chromium-store'", $pw);
		$sec = (string)file_get_contents($root . '/SECURITY.md');
		self::assertStringContainsString('info@software-by-design.de', $sec);
		self::assertStringNotContainsString('Cursor', $sec);
		self::assertStringNotContainsString('cursor', strtolower($sec));
	}
}
