<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\CatalogService;
use PHPUnit\Framework\TestCase;

class CatalogStarterTest extends TestCase
{
	public function testStarterHasAtLeastEightItemsAndTwoFree(): void
	{
		self::assertGreaterThanOrEqual(8, count(CatalogService::STARTER_DE));
		$free = 0;
		foreach (CatalogService::STARTER_DE as $row) {
			if ((int)$row['price'] === 0) {
				$free++;
			}
		}
		self::assertGreaterThanOrEqual(2, $free);
	}

	public function testStarterApplySetsParAndOnHandForPulse(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/CatalogService.php');
		self::assertMatchesRegularExpression('/applyStarterDe[\s\S]*?12,[\s\S]*?20,/m', $src);
		self::assertStringContainsString('copyToSite', $src);
	}
}
