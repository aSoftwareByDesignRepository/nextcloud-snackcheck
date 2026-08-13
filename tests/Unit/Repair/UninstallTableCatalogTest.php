<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Repair;

use OCA\SnackCheck\Db\SnackCheckTableCatalog;
use OCA\SnackCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

class UninstallTableCatalogTest extends TestCase
{
	public function testAllSnkTablesListed(): void
	{
		self::assertSame(SnackCheckTableCatalog::TABLES, UninstallDropTables::TABLES);
		foreach (UninstallDropTables::TABLES as $t) {
			self::assertLessThanOrEqual(27, strlen($t), $t);
			self::assertStringStartsWith('snk_', $t);
		}
		$sorted = SnackCheckTableCatalog::TABLES;
		$copy = $sorted;
		sort($copy);
		self::assertSame($copy, $sorted, 'catalog must stay sorted');
	}
}
