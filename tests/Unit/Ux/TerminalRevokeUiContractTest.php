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
		// Settings partial is a thin wrapper mounting the canonical shared page;
		// the terminal list + revoke controls live in templates/common/.
		$src = (string)file_get_contents($this->root() . '/templates/parts/settings/license.php');
		self::assertStringContainsString("common/license-page.php", $src);
		self::assertStringContainsString('snk-license-key', $src);

		$shared = (string)file_get_contents($this->root() . '/templates/common/license-page.php');
		self::assertStringContainsString('id="snk-terminal-list-body"', $shared);
		self::assertStringContainsString('snk-terminal-revoke', $shared);
		self::assertStringContainsString('data-device-id', $shared);
		self::assertStringContainsString('<dialog', $shared);
		self::assertStringNotContainsString('window.confirm', $shared);

		// PageController must feed the device card + revoke endpoint.
		$ctrl = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		foreach (['terminals', 'terminalDevicesUsed', 'terminalDevicesLimit',
			'showTerminal', 'apiRegisterDeviceUrl', 'apiRevokeDeviceUrl',
			'apiLicenseUrl', 'apiClearLicenseUrl'] as $param) {
			self::assertStringContainsString("'{$param}'", $ctrl);
		}
		self::assertStringContainsString('productsUrl', $ctrl);
		self::assertStringContainsString('SupportUsLinks', $ctrl);
	}

	public function testJsWiresRevokeAndClearLicense(): void
	{
		// Register/revoke/clear are handled by the canonical shared JS against
		// the data-api-* endpoints — no bespoke app.js license handlers remain.
		$js = (string)file_get_contents($this->root() . '/js/common/license-page.js');
		self::assertStringContainsString('apiRevokeDevice', $js);
		self::assertStringContainsString('deviceId', $js);
		self::assertStringContainsString('apiClearLicense', $js);
		self::assertStringContainsString('apiRegisterDevice', $js);
		self::assertStringNotContainsString('window.confirm', $js);

		$appJs = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringNotContainsString("action === 'revoke-terminal'", $appJs);
		self::assertStringNotContainsString("action === 'clear-license'", $appJs);
		self::assertStringNotContainsString("kind === 'license'", $appJs);
		self::assertStringNotContainsString("kind === 'terminal'", $appJs);
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
