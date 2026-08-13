<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/** Argus: unlock ACL deny must not distinguish from wrong PIN; lockout is device-scoped. */
final class UnlockOracleAndLockoutContractTest extends TestCase
{
	public function testAclDenyUsesUnlockInvalidNotPermissionDenied(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/UnlockService.php');
		self::assertMatchesRegularExpression(
			'/canAccessApp\(\$userId\)\)\s*\{[\s\S]{0,200}recordUnlockFailure[\s\S]{0,120}unlock_invalid/',
			$src
		);
		self::assertDoesNotMatchRegularExpression(
			'/canAccessApp\(\$userId\)\)\s*\{[\s\S]{0,120}permission_denied/',
			$src
		);
	}

	public function testUnlockLockoutKeyIsDeviceScopedNotIp(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertMatchesRegularExpression(
			"/function unlockVerify[\s\S]{0,500}'dev:'\s*\.\s*\\\$device->getId\(\)/",
			$src
		);
		self::assertDoesNotMatchRegularExpression(
			"/function unlockVerify[\s\S]{0,500}getRemoteAddress/",
			$src
		);
	}
}
