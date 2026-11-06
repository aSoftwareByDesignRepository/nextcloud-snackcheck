<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: people pickers and colleague lists never offer deactivated
 * Nextcloud accounts — they can never sign in or be booked for.
 *
 * Guards regressions where a `userManager->search()` loop forgets the
 * `isEnabled()` check (Tagestreff Winter support case: deactivated accounts
 * cluttering the employee assignment surface).
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	private function src(string $rel): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
	}

	public function testAdminUserSearchSkipsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Controller/ApiController.php');
		$this->assertMatchesRegularExpression(
			'/function searchUsers[\s\S]{0,3000}if \(!\$u->isEnabled\(\)\)/',
			$src,
			'ApiController::searchUsers must skip deactivated accounts in both scopes'
		);
	}

	public function testDeviceColleaguePickerSkipsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Controller/DeviceApiController.php');
		$this->assertMatchesRegularExpression(
			'/userManager->search\(\$q, \$searchLimit\)[\s\S]{0,1500}if \(!\$user->isEnabled\(\)\)/',
			$src,
			'DeviceApiController colleague search must skip deactivated accounts'
		);
	}
}
