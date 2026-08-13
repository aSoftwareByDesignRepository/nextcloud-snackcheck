<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Zeus MF-03 / NG-04 — terminal capacity must be DB-gated (multi-node safe).
 */
final class TerminalCapacityDbLockContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testTerminalDeviceServiceLocksDbGateNotFileLock(): void
	{
		$src = (string)file_get_contents($this->root() . '/lib/Service/TerminalDeviceService.php');
		self::assertStringContainsString('LockGate', $src);
		self::assertStringContainsString('CAPACITY_LOCK', $src);
		self::assertStringContainsString('KEY_TERMINAL_CAPACITY', $src);
		self::assertStringContainsString('$this->lockGate->lock(self::CAPACITY_LOCK)', $src);
		self::assertStringNotContainsString('acquireLock', $src);
		self::assertDoesNotMatchRegularExpression('/use OCP\\\\Lock\\\\ILockingProvider/', $src);
		self::assertMatchesRegularExpression(
			'/function register[\s\S]{0,900}lockGate->lock\(self::CAPACITY_LOCK\)/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function trimToLimit[\s\S]{0,600}lockGate->lock\(self::CAPACITY_LOCK\)/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function revoke[\s\S]{0,500}lockGate->lock\(self::CAPACITY_LOCK\)/',
			$src
		);
	}

	public function testLockGateDefinesTerminalCapacityKey(): void
	{
		$src = (string)file_get_contents($this->root() . '/lib/Db/LockGate.php');
		self::assertStringContainsString("KEY_TERMINAL_CAPACITY = 'terminal_capacity'", $src);
		self::assertStringContainsString('FOR UPDATE', $src);
		self::assertStringContainsString('snk_locks', $src);
	}

	public function testMigrationSeedsTerminalCapacityLock(): void
	{
		$path = $this->root() . '/lib/Migration/Version1006Date20260810220000.php';
		self::assertFileExists($path);
		$src = (string)file_get_contents($path);
		self::assertStringContainsString('KEY_TERMINAL_CAPACITY', $src);
		self::assertStringContainsString('snk_locks', $src);
	}
}
