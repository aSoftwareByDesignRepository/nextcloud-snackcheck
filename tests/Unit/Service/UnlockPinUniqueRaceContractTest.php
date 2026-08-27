<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\UnlockService;
use PHPUnit\Framework\TestCase;

/**
 * Concurrent PIN/QR assign must map unique-index races to 422, never opaque 500.
 */
final class UnlockPinUniqueRaceContractTest extends TestCase
{
	public function testSetPinAndQrMapUniqueConstraintToValidationFailed(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/UnlockService.php');
		self::assertStringContainsString('use OCP\\DB\\Exception as DbException', $src);
		self::assertMatchesRegularExpression(
			'/function setPin[\s\S]{0,1800}REASON_UNIQUE_CONSTRAINT_VIOLATION[\s\S]{0,200}PIN already in use/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function setQr[\s\S]{0,1800}REASON_UNIQUE_CONSTRAINT_VIOLATION[\s\S]{0,200}QR already in use/',
			$src
		);
		self::assertTrue(method_exists(UnlockService::class, 'setPin'));
		self::assertTrue(method_exists(UnlockService::class, 'setQr'));
	}
}
