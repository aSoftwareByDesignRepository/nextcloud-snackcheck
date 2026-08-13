<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Db;

use OCA\SnackCheck\Db\SnackCheckTableCatalog;
use OCA\SnackCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

final class SnackCheckTableCatalogTest extends TestCase
{
	public function testUninstallTablesMatchCatalogAndLength(): void
	{
		self::assertSame(SnackCheckTableCatalog::TABLES, UninstallDropTables::TABLES);
		foreach (SnackCheckTableCatalog::TABLES as $table) {
			self::assertLessThanOrEqual(27, strlen($table), $table);
			self::assertStringStartsWith('snk_', $table);
		}
		$sorted = SnackCheckTableCatalog::TABLES;
		sort($sorted);
		self::assertSame($sorted, SnackCheckTableCatalog::TABLES, 'catalog must stay sorted');
	}
}
