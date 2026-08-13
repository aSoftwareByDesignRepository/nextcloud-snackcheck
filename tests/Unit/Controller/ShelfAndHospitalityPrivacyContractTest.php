<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/** Aristoteles — shelf inactive → not_found; hospitality allowlist gated to app admin. */
final class ShelfAndHospitalityPrivacyContractTest extends TestCase
{
	public function testShelfRejectsInactiveItemsAsNotFound(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			'/function shelf\(int \$itemId\)[\s\S]{0,500}getActive\(\)[\s\S]{0,120}not_found/',
			$src
		);
	}

	public function testShelfAssertsAccessBeforeCatalogProbe(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		// Argus MF-A04: assertAccess must precede catalog->get (SKU existence oracle).
		self::assertMatchesRegularExpression(
			'/function shelf\(int \$itemId\)[\s\S]{0,280}assertAccess\(\$user\)[\s\S]{0,120}catalog->get\(\$itemId\)/',
			$src
		);
	}

	public function testShelfPassesPeriodClosedLikeLogPage(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			'/function shelf\(int \$itemId\)[\s\S]{0,1100}periodClosed\'\s*=>\s*\$open === null/',
			$src
		);
	}

	public function testHospitalityAllowlistOnlyForAppAdmin(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			'/function hospitalityView\(string \$viewerUid[\s\S]{0,500}isAppAdmin\(\$viewerUid\)/',
			$src
		);
	}

	public function testDeviceFailUsesDomainRetryAfter(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertMatchesRegularExpression(
			'/function deviceFail[\s\S]{0,800}retryAfterSeconds/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function deviceFail[\s\S]{0,900}body\[.retryAfter.\]/',
			$src
		);
	}
}
