<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Zeus Absolute No-Go: do not treat snk_unlock_tokens as the unlock session store.
 * Sessions are intentionally ICache createDistributed('snackcheck_unlock') with 120s TTL.
 */
final class UnlockSessionArchitectureContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testUnlockServiceUsesDistributedCacheNotDbTable(): void
	{
		$src = (string)file_get_contents($this->root() . '/lib/Service/UnlockService.php');
		self::assertStringContainsString("createDistributed('snackcheck_unlock')", $src);
		self::assertStringContainsString('TOKEN_TTL_SECONDS', $src);
		self::assertStringContainsString("tok:' . hash('sha256'", $src);
		// Must never write unlock sessions to SQL from UnlockService.
		self::assertStringNotContainsString('snk_unlock_tokens', $src);
		self::assertStringNotContainsString('UnlockTokenMapper', $src);
	}

	public function testNoUnlockTokenMapperWriterExists(): void
	{
		$lib = $this->root() . '/lib';
		$hits = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
		foreach ($it as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$path = $file->getPathname();
			if (str_contains($path, '/Migration/')) {
				continue; // schema may declare the reserved table
			}
			$src = (string)file_get_contents($path);
			if (preg_match('/\bsnk_unlock_tokens\b/', $src)
				&& preg_match('/\b(insert|INSERT INTO|->insert\()/', $src)) {
				$hits[] = $path;
			}
		}
		self::assertSame([], $hits, 'No PHP writer may insert into snk_unlock_tokens');
	}

	public function testVoidLogDoesNotPreLockFindForSiteAcl(): void
	{
		$api = (string)file_get_contents($this->root() . '/lib/Controller/ApiController.php');
		self::assertMatchesRegularExpression(
			'/function voidLog[\s\S]*?logs->void\(/',
			$api
		);
		// Unlocked find()+assertCanManageSite before void must stay gone.
		self::assertDoesNotMatchRegularExpression(
			'/function voidLog[\s\S]{0,400}logMapper->find\(/',
			$api
		);
	}
}
