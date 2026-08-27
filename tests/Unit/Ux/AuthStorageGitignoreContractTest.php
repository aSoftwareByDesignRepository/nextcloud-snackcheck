<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/** Argus: Playwright session cookies must never enter the public ship tree. */
final class AuthStorageGitignoreContractTest extends TestCase
{
	public function testAuthDirectoryIsGitignored(): void
	{
		$root = dirname(__DIR__, 3);
		$gitignore = (string)file_get_contents($root . '/.gitignore');
		self::assertMatchesRegularExpression('/^\.auth\/?\s*$/m', $gitignore);
		self::assertStringNotContainsString('storage-state.json', (string)shell_exec(
			'cd ' . escapeshellarg($root) . ' && git ls-files .auth 2>/dev/null'
		));
	}
}
