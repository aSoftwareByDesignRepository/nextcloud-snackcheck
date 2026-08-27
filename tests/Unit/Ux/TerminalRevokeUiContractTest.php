<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Stolen/lost tablet recovery must be one clear admin action — not API-only.
 */
final class TerminalRevokeUiContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testLicenseSettingsListsTerminalsWithRevoke(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/parts/settings/license.php');
		self::assertStringContainsString('snk-term-list', $src);
		self::assertStringContainsString('data-snk-action="revoke-terminal"', $src);
		self::assertStringContainsString("\$_['terminals']", $src);
		self::assertStringContainsString('Revoke a tablet if it is lost', $src);
		self::assertStringContainsString('What this license does', $src);
		self::assertStringContainsString('SupportUsLinks::SITE_ORIGIN', $src);
		self::assertStringContainsString('productsUrl', $src);
		self::assertStringContainsString('data-snk-action="clear-license"', $src);
		self::assertStringContainsString('Ask for a license', $src);
	}

	public function testJsWiresRevokeAndClearLicense(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("action === 'revoke-terminal'", $js);
		self::assertStringContainsString('/api/admin/license/terminals/revoke', $js);
		self::assertStringContainsString('deviceId', $js);
		self::assertStringContainsString("action === 'clear-license'", $js);
		self::assertStringContainsString("api('DELETE'", $js);
		self::assertStringContainsString('/api/admin/license', $js);
		// Danger dialogs focus Cancel first.
		self::assertStringContainsString('button[value="cancel"]', $js);
	}

	public function testSettingsRouteRedirectsLegacyPeriodsSitesNotSupportBody(): void
	{
		$routes = (string)file_get_contents($this->root() . '/appinfo/routes.php');
		self::assertStringContainsString('SettingsSectionCatalog::routeRequirement()', $routes);
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/section === 'periods'[\s\S]{0,200}RedirectResponse[\s\S]{0,120}page\.periods/",
			$page
		);
		self::assertMatchesRegularExpression(
			"/section === 'sites'[\s\S]{0,200}RedirectResponse[\s\S]{0,120}page\.sites/",
			$page
		);
		$settings = (string)file_get_contents($this->root() . '/templates/pages/settings.php');
		self::assertStringNotContainsString("'periods' =>", $settings);
		self::assertStringNotContainsString("'sites' =>", $settings);
	}
}
