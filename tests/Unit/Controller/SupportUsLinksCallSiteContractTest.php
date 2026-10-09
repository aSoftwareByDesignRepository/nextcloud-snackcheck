<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use OCA\SnackCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Every SupportUsLinks call site in PageController must resolve to a real
 * method/constant on the shared class. Atlas web_api re-verify 20261009 found
 * /settings/license returning HTTP 500: the 5fcdc83 support-us resync dropped
 * productsUrl()/licenseMailto() while the license branch kept calling them —
 * a call-site/class drift invisible to every suite until the page rendered.
 */
final class SupportUsLinksCallSiteContractTest extends TestCase
{
	private function controllerSrc(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
	}

	public function testEverySupportUsLinksMethodCallExists(): void
	{
		$src = $this->controllerSrc();
		preg_match_all('/\$supportLinks->(\w+)\(/', $src, $m);
		self::assertNotEmpty($m[1], 'expected SupportUsLinks call sites in PageController');
		$links = new SupportUsLinks('SnackCheck', true, 'https://example.test/settings/license#snk-license-key');
		foreach (array_unique($m[1]) as $method) {
			self::assertTrue(
				method_exists($links, $method),
				"PageController calls SupportUsLinks::{$method}() — method does not exist on the shared class"
			);
		}
	}

	public function testEverySupportUsLinksConstantCallExists(): void
	{
		$src = $this->controllerSrc();
		preg_match_all('/SupportUsLinks::(\w+)\b/', $src, $m);
		foreach (array_unique($m[1]) as $member) {
			if ($member === 'class') {
				continue;
			}
			$exists = defined(SupportUsLinks::class . '::' . $member)
				|| method_exists(SupportUsLinks::class, $member);
			self::assertTrue($exists, "PageController references SupportUsLinks::{$member} — not defined");
		}
	}

	public function testLicensePayloadKeysProduced(): void
	{
		$src = $this->controllerSrc();
		self::assertStringContainsString("\$payload['productsUrl']", $src);
		self::assertStringContainsString("\$payload['licenseRenewMailto']", $src);
		self::assertStringContainsString("\$payload['instanceId']", $src);
	}

	public function testLicenseRenewMailtoKeepsLocalizedSubject(): void
	{
		$src = $this->controllerSrc();
		self::assertStringContainsString('SupportUsLinks::CONTACT_EMAIL', $src);
		self::assertStringContainsString('rawurlencode', $src);
		self::assertStringContainsString('Küchen-Tablet-Lizenz', $src);
		self::assertStringContainsString('kitchen tablet license', $src);
	}

	public function testSupportUsLinksLicenseCtorAndLocaleHelpers(): void
	{
		$links = new SupportUsLinks('SnackCheck', true, '/settings/license#snk-license-key');
		self::assertTrue($links->hasOfficialMobileLicenses());
		self::assertSame('/settings/license#snk-license-key', $links->licensePageUrl());
		self::assertTrue($links->isGermanLocale('de'));
		self::assertFalse($links->isGermanLocale('en'));
		self::assertStringContainsString('/de/apps.html', $links->appsPageUrl('de'));
		self::assertStringContainsString('/en/apps.html', $links->appsPageUrl('en'));
	}
}
