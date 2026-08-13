<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

class TableNameLengthTest extends TestCase
{
	public function testMigrationTableNamesWithin27(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Migration/Version1000Date20260810120000.php');
		preg_match_all("/hasTable\\('([^']+)'\\)/", $src, $m);
		self::assertNotEmpty($m[1]);
		foreach ($m[1] as $name) {
			self::assertLessThanOrEqual(27, strlen($name), $name);
		}
	}

	public function testInfoXmlDeclaresMysqlAndPgsqlUnderDependencies(): void
	{
		$path = __DIR__ . '/../../../appinfo/info.xml';
		$raw = file_get_contents($path);
		self::assertNotFalse($raw);
		$xml = simplexml_load_string($raw);
		self::assertNotFalse($xml, 'info.xml must parse as XML');
		$dbs = [];
		foreach ($xml->dependencies->database as $db) {
			$dbs[] = (string)$db;
		}
		self::assertContains('mysql', $dbs);
		self::assertContains('pgsql', $dbs);
	}
}
