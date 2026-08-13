<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Browser navigations do not send requesttoken — every PageController action
 * and every download/link GET must be NoCSRFRequired or users see "CSRF check failed".
 */
final class PageCsrfExemptContractTest extends TestCase
{
	public function testEveryPublicPageActionIsCsrfExempt(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		preg_match_all('/#\[NoAdminRequired\]\s*(?:#\[[^\]]+\]\s*)*public function (\w+)\(/', $src, $m);
		self::assertNotEmpty($m[1], 'expected page actions');
		foreach ($m[1] as $method) {
			self::assertMatchesRegularExpression(
				'/#\[NoAdminRequired\]\s*#\[NoCSRFRequired\]\s*public function ' . preg_quote($method, '/') . '\(/',
				$src,
				$method . ' must be NoCSRFRequired for browser GET navigation'
			);
		}
	}

	public function testBrowserDownloadApisAreCsrfExempt(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		foreach ([
			'downloadPayroll',
			'downloadHospitality',
			'shoppingList',
			'downloadMyMonthPdf',
			'brReport',
			'complimentaryExport',
			'shelfQr',
		] as $method) {
			self::assertMatchesRegularExpression(
				'/#\[NoAdminRequired\]\s*#\[NoCSRFRequired\]\s*public function ' . preg_quote($method, '/') . '\(/',
				$src,
				$method . ' is opened via href/window.location and must skip CSRF'
			);
		}
	}

	public function testTemplatesNeverCallRemovedServerGetUrlGenerator(): void
	{
		$root = dirname(__DIR__, 3) . '/templates';
		$hits = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$src = (string)file_get_contents($file->getPathname());
			if (str_contains($src, 'getURLGenerator()') || str_contains($src, 'OC::$server')) {
				$hits[] = $file->getFilename();
			}
		}
		self::assertSame([], $hits, 'NC34 removed Server::getURLGenerator(); inject IURLGenerator via page params');
		$page = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			"/\\\$params\\['urlGenerator'\\]\\s*=\\s*\\\$this->urlGenerator/",
			$page
		);
		$main = (string)file_get_contents($root . '/main.php');
		self::assertStringContainsString("\$urlGenerator = \$_['urlGenerator']", $main);
	}
}
