<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Absolute No-Go: oc_file_locks.key is varchar(64). Truncated keys sticky-lock forever (429).
 */
final class FileLockKeyLengthContractTest extends TestCase
{
	public function testExclusiveFileLockKeysAreBudgetedTo64Chars(): void
	{
		$cases = [
			'RateLimitService.php' => "snkrl/",
			'UnlockService.php' => "snkuf/",
			'DigestMailService.php' => "snkdg/",
		];
		$root = dirname(__DIR__, 3) . '/lib/Service';
		foreach ($cases as $file => $prefix) {
			$src = (string)file_get_contents($root . '/' . $file);
			self::assertStringContainsString(
				"'" . $prefix . "' . substr(hash('sha256'",
				$src,
				$file . ' must prefix ' . $prefix,
			);
			self::assertStringContainsString(', 0, 58)', $src, $file . ' must keep hash ≤58 (prefix 6 → 64)');
			self::assertLessThanOrEqual(6, strlen($prefix));
		}
	}
}
