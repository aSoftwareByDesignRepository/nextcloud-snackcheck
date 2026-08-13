<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Aristoteles MF — createLog must re-validate item + attribution under the period lock.
 */
final class CreateLogUnderLockContractTest extends TestCase
{
	public function testItemAndAttributionResolvedAfterPeriodLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ConsumptionLogService.php');
		self::assertMatchesRegularExpression(
			'/public function create\(array \$input\): array[\s\S]*?beginTransaction\(\);[\s\S]{0,600}periodMapper->lockRow[\s\S]{0,1200}catalog->getForUpdate\(\$itemId\)[\s\S]{0,500}resolveAttribution/',
			$src
		);
		self::assertStringContainsString('under the same period lock', $src);
		self::assertStringContainsString('period row → catalog row', $src);
		self::assertDoesNotMatchRegularExpression(
			'/public function create\(array \$input\): array[\s\S]*?resolveAttribution\([\s\S]{0,300}?beginTransaction\(\)/',
			$src
		);
	}
}
