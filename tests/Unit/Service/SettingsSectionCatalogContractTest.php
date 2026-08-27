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
		self::assertStringContainsString('SettingsSectionCatalog::DEFAULT_SECTION', $routes);
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
}
