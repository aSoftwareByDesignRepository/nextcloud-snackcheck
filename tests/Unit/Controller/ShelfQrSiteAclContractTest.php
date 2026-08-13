<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/** Argus: shelf QR must not leak cross-site catalog items (BOLA). */
final class ShelfQrSiteAclContractTest extends TestCase
{
	public function testShelfQrAssertsSiteManage(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertMatchesRegularExpression(
			'/function shelfQr[\s\S]{0,500}assertCanManageSite\(\$user,\s*\(int\)\$item->getSiteId\(\)\)/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function shelfQr[\s\S]{0,350}catalog->get\(\$id\)/',
			$src
		);
	}

	public function testDeviceUnlockPathsBindPeekToDeviceId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertMatchesRegularExpression(
			'/function unlockVerify[\s\S]{0,900}\(string\)\$device->getId\(\)/',
			$src
		);
		self::assertSame(4, substr_count($src, 'peekUnlockToken($token, (string)$device->getId())'));
	}

	public function testUnlockServiceHashesQrWithPepperPrefix(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/UnlockService.php');
		self::assertStringContainsString("snk-qr|", $src);
		self::assertStringContainsString('hash_hmac(\'sha256\', $payload', $src);
		self::assertStringContainsString('hash_equals($bound, $requiredDeviceId)', $src);
	}
}
