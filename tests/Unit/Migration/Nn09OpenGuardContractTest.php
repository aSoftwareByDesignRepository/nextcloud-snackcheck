<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Migration;

use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Migration\Version1004Date20260810193000;
use OCA\SnackCheck\Service\PeriodService;
use PHPUnit\Framework\TestCase;

/** NN-09: unique open_guard + close/reopen/open paths keep the invariant. */
final class Nn09OpenGuardContractTest extends TestCase
{
	public function testMigrationDefinesUniqueOpenGuard(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Migration/Version1004Date20260810193000.php');
		self::assertStringContainsString('snk_periods_open_uq', $src);
		self::assertStringContainsString('open_guard', $src);
		self::assertStringContainsString('NN-09', $src);
		self::assertTrue(class_exists(Version1004Date20260810193000::class));
	}

	public function testPeriodEntityTracksOpenGuard(): void
	{
		$p = new Period();
		$p->setOpenGuard(1);
		self::assertSame(1, $p->getOpenGuard());
		$p->setOpenGuard(null);
		self::assertNull($p->getOpenGuard());
	}

	public function testPeriodServiceWritesOpenGuardOnLifecycle(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PeriodService.php');
		self::assertStringContainsString('setOpenGuard(1)', $src);
		self::assertStringContainsString('setOpenGuard(null)', $src);
		self::assertStringContainsString('lockOpenPeriodGate()', $src);
		// close() must take the same gate as open/reopen
		$closePos = strpos($src, 'function close(');
		self::assertNotFalse($closePos);
		$chunk = substr($src, $closePos, 900);
		self::assertStringContainsString('lockOpenPeriodGate()', $chunk);
		self::assertTrue(method_exists(PeriodService::class, 'close'));
	}
}
