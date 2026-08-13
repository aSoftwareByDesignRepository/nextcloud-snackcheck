<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/** AC-35: privacy totals-only still exposes kitchen-admin proxy-log form on Users. */
final class UsersPrivacyProxyContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testUsersPageKeepsProxyFormWhenPrivacyHidesLines(): void
	{
		$users = (string)file_get_contents($this->root() . '/templates/pages/users.php');
		self::assertStringContainsString("!empty(\$_['privacyTotalsOnly'])", $users);
		self::assertStringContainsString('Itemized lines hidden by privacy mode.', $users);
		self::assertStringContainsString("!empty(\$_['canProxy'])", $users);
		self::assertStringContainsString('data-snk-proxy-fields', $users);
		self::assertStringContainsString('data-snk-action="log"', $users);
		self::assertStringContainsString("value=\"proxy\"", $users);
		self::assertStringContainsString('proxyItems', $users);
		self::assertStringContainsString('Available even when privacy hides itemized lines.', $users);
	}

	public function testUsersControllerPassesProxyCatalog(): void
	{
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			'/function users\(\)[\s\S]{0,2500}\'canProxy\'/',
			$page
		);
		self::assertMatchesRegularExpression(
			'/function users\(\)[\s\S]{0,2500}\'proxyItems\'/',
			$page
		);
		self::assertMatchesRegularExpression(
			'/function users\(\)[\s\S]{0,2500}canManageSite/',
			$page
		);
	}
}
