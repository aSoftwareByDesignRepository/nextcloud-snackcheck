<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class DeviceAuthOverCapContractTest extends TestCase
{
	public function testAuthenticateDeviceChecksActiveCountAgainstLimit(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/DeviceApiController.php');
		self::assertStringContainsString('getActiveCount()', $src);
		self::assertStringContainsString('getDeviceLimit()', $src);
		self::assertStringContainsString('PaymentRequiredException', $src);
		self::assertMatchesRegularExpression(
			'/getActiveCount\(\)[\s\S]{0,120}getDeviceLimit\(\)|getDeviceLimit\(\)[\s\S]{0,120}getActiveCount\(\)/',
			$src,
		);
	}

	public function testPinHashUniqueMigrationExists(): void
	{
		$mig = (string)file_get_contents(__DIR__ . '/../../../lib/Migration/Version1003Date20260810180000.php');
		self::assertStringContainsString('snk_pins_hash_uq', $mig);
		self::assertStringContainsString('pin_hash', $mig);
		$v1000 = (string)file_get_contents(__DIR__ . '/../../../lib/Migration/Version1000Date20260810120000.php');
		self::assertStringContainsString('snk_pins_hash_uq', $v1000);
	}

	public function testAuditInsideLogTransaction(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/ConsumptionLogService.php');
		self::assertMatchesRegularExpression(
			'/audit->record\([\s\S]{0,200}?db->commit\(\)/m',
			$src,
		);
	}

	public function testSupportMacrosShipped(): void
	{
		self::assertFileExists(__DIR__ . '/../../../docs/SUPPORT-MACROS-EN.md');
		self::assertFileExists(__DIR__ . '/../../../docs/SUPPORT-MACROS-DE.md');
	}
}
