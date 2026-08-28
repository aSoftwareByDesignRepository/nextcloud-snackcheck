<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\SettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Catalog must stay the SSoT for routes, dispatcher, and both nav surfaces.
 */
final class SettingsSectionCatalogContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testRouteRequirementMatchesSectionsPlusRedirectAliases(): void
	{
		$req = SettingsSectionCatalog::routeRequirement();
		foreach (SettingsSectionCatalog::SECTIONS as $slug) {
			self::assertStringContainsString($slug, $req);
		}
		foreach (SettingsSectionCatalog::REDIRECT_ALIASES as $alias) {
			self::assertStringContainsString($alias, $req);
		}
		$routes = (string)file_get_contents($this->root() . '/appinfo/routes.php');
		self::assertStringContainsString('SettingsSectionCatalog::routeRequirement()', $routes);
		self::assertStringContainsString("page#settingsIndex", $routes);
		self::assertStringContainsString("'/settings/{section}'", $routes);
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertStringContainsString('function settingsIndex', $page);
		self::assertStringContainsString('SettingsSectionCatalog::DEFAULT_SECTION', $page);
		self::assertStringContainsString('snackcheck.page.settingsIndex', $page);
		self::assertStringNotContainsString("'route' => 'snackcheck.page.settings'", $page);
	}

	public function testDispatcherMapCoversEverySectionLiterally(): void
	{
		$tpl = (string)file_get_contents($this->root() . '/templates/pages/settings.php');
		self::assertStringContainsString('settings-nav.php', $tpl);
		self::assertStringContainsString('unknown section reached the template dispatcher', $tpl);
		foreach (SettingsSectionCatalog::SECTIONS as $slug) {
			self::assertStringContainsString("'{$slug}' => '{$slug}.php'", $tpl);
			$path = $this->root() . '/templates/parts/settings/' . $slug . '.php';
			self::assertFileExists($path, "missing partial for {$slug}");
		}
		self::assertStringNotContainsString("'periods' =>", $tpl);
		self::assertStringNotContainsString("'sites' =>", $tpl);
	}

	public function testControllerRejectsUnknownAndInjectsNav(): void
	{
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertStringContainsString('SettingsSectionCatalog', $page);
		self::assertStringContainsString('NotFoundResponse', $page);
		self::assertStringContainsString('settingsSectionLabels', $page);
		self::assertStringContainsString("'settingsSections'", $page);
		self::assertStringContainsString('NotFoundResponse', $page);
	}

	public function testNavSurfacesExist(): void
	{
		$nav = (string)file_get_contents($this->root() . '/templates/common/navigation.php');
		self::assertStringContainsString('snk-nav__sublist', $nav);
		self::assertStringContainsString('settingsChildren', $nav);
		$chip = (string)file_get_contents($this->root() . '/templates/parts/settings-nav.php');
		self::assertStringContainsString('settingsSectionLabels', $chip);
		self::assertStringContainsString('Settings pages', $chip);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-nav__sublist', $css);
		self::assertStringContainsString('Keep main-text ink', $css);
		self::assertStringNotContainsString(
			'.snk-settings-nav__link.is-active {\n	background: var(--snk-primary);',
			$css
		);
	}

	public function testLabelsAndHelpForEverySection(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$cat = new SettingsSectionCatalog();
		foreach (SettingsSectionCatalog::SECTIONS as $slug) {
			self::assertNotSame('', $cat->navLabel($l, $slug));
			self::assertNotSame('', $cat->label($l, $slug));
			if ($slug !== 'support') {
				self::assertNotSame('', $cat->help($l, $slug));
			}
		}
	}

	public function testSettingsPartialsAreValidPhp(): void
	{
		$dir = $this->root() . '/templates/parts/settings';
		foreach (SettingsSectionCatalog::SECTIONS as $slug) {
			$path = $dir . '/' . $slug . '.php';
			self::assertFileExists($path);
			$out = [];
			$code = 0;
			exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
			self::assertSame(0, $code, $slug . ': ' . implode("\n", $out));
		}
		$support = (string)file_get_contents($dir . '/support.php');
		self::assertDoesNotMatchRegularExpression('/^\s*<\?php\s+endif/m', $support);
		self::assertStringNotContainsString('<?php endif', $support);
		self::assertStringContainsString('support-us-section.php', $support);
		self::assertStringContainsString('SupportUsLinks', $support);
		self::assertStringContainsString('public/docs/DEVICE-SHORTLIST.md', $support);
		self::assertFileExists($this->root() . '/public/docs/DEVICE-SHORTLIST.md');
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertStringContainsString("\$section === 'support'", $page);
		self::assertStringContainsString('new SupportUsLinks(', $page);
		self::assertStringContainsString('function settingsIndex', $page);
		$routes = (string)file_get_contents($this->root() . '/appinfo/routes.php');
		self::assertStringContainsString("page#settingsIndex", $routes);
		$access = (string)file_get_contents($dir . '/access.php');
		self::assertStringContainsString('snk-settings-block', $access);
		self::assertStringContainsString('snk-callout--info', $access);
		self::assertStringContainsString('snk-form--settings', $access);
		self::assertStringContainsString('snk-access-roster', $access);
		self::assertStringContainsString('snk-access-restricted', $access);
		self::assertStringContainsString('Door access', $access);
		$unlock = (string)file_get_contents($dir . '/unlock.php');
		self::assertStringContainsString('snk-callout--info', $unlock);
		self::assertStringContainsString('Unlock for kitchen tablets', $unlock);
		self::assertStringContainsString('snk-settings-methods', $unlock);
		self::assertStringContainsString('snk-settings-method__tag', $unlock);
		self::assertStringContainsString('snk-unlock-choice-lead', $unlock);
		self::assertStringNotContainsString('snk-settings-step', $unlock);
		self::assertStringContainsString('data-snk-form="unlock-pin"', $unlock);
		self::assertStringContainsString('data-snk-form="unlock-qr"', $unlock);
		self::assertStringContainsString('aria-describedby="snk-unlock-pin-hint"', $unlock);
		self::assertStringContainsString('aria-describedby="snk-unlock-qr-hint"', $unlock);
		self::assertStringContainsString('autocomplete="new-password"', $unlock);
		self::assertStringNotContainsString('snk-settings-split', $unlock);
		$chip = (string)file_get_contents($this->root() . '/templates/parts/snk-chip-field.php');
		self::assertStringContainsString('Nobody selected yet — search above', $chip);
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("kind === 'unlock-pin'", $js);
		self::assertStringContainsString('pinInput.value = \'\'', $js);
		self::assertStringContainsString('qrInput.value = \'\'', $js);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-form--settings', $css);
		self::assertStringContainsString('.snk-settings-panel', $css);
		self::assertStringContainsString('.snk-form-actions', $css);
		self::assertStringContainsString('.snk-settings-methods', $css);
		self::assertStringContainsString('.snk-settings-method__tag', $css);
		self::assertStringContainsString('.snk-unlock-choice-lead', $css);
	}
}
