<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/** Zeus MF: dual license rows must never be treated as unlicensed. */
final class LicenseStateSingletonContractTest extends TestCase
{
	public function testFindCurrentNeverReturnsNullOnMultipleObjectsPath(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/LicenseStateMapper.php');
		self::assertStringContainsString('findEntities($qb)', $src);
		self::assertStringContainsString('return $entities[0] ?? null', $src);
		self::assertStringNotContainsString('MultipleObjectsReturnedException', $src);
		self::assertStringContainsString('deleteOtherThan', $src);
		self::assertStringContainsString('setSingletonGuard(1)', $src);
	}

	public function testMigrationEnforcesSingletonUnique(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version1005Date20260810210000.php'
		);
		self::assertStringContainsString('singleton_guard', $src);
		self::assertStringContainsString('snk_lic_single_uq', $src);
		self::assertStringContainsString('License singleton repair', $src);
	}
}
