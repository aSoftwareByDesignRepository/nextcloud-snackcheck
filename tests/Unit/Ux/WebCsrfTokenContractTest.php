<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Browser POSTs must send Nextcloud's CSRF token or SecurityMiddleware returns HTTP 412.
 *
 * NC layout.user.php ships head[data-requesttoken] + OC.requestToken — NOT meta[name=requesttoken].
 * Reading only the missing meta tag empties the requesttoken header → every money-path POST fails.
 */
final class WebCsrfTokenContractTest extends TestCase
{
	private function js(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
	}

	public function testTokenResolvesOcRequestTokenBeforeMeta(): void
	{
		$js = $this->js();
		self::assertStringContainsString('function token()', $js);
		self::assertStringContainsString('OC.requestToken', $js);
		self::assertStringContainsString("getAttribute('data-requesttoken')", $js);
		self::assertStringContainsString("meta[name=\"requesttoken\"]", $js);
		// Priority: OC.requestToken must appear before the meta-only fallback in source order.
		$ocPos = strpos($js, 'OC.requestToken');
		$metaPos = strpos($js, 'meta[name="requesttoken"]');
		self::assertNotFalse($ocPos);
		self::assertNotFalse($metaPos);
		self::assertLessThan($metaPos, $ocPos, 'OC.requestToken must win over meta fallback');
	}

	public function testTokenDoesNotRelyOnMetaAlone(): void
	{
		$js = $this->js();
		// The historical bug: only meta under head — always empty on NC layouts.
		self::assertDoesNotMatchRegularExpression(
			'/function token\(\)\s*\{\s*const el = document\.querySelector\(\'head meta\[name="requesttoken"\]\'\);\s*return el \? el\.getAttribute\(\'content\'\) : \'\';\s*\}/',
			$js,
			'Must not resolve CSRF from missing meta alone'
		);
	}

	public function testApiSendsTokenInHeaderBodyAndSameOriginCredentials(): void
	{
		$js = $this->js();
		self::assertStringContainsString('headers = { requesttoken: csrf }', $js);
		self::assertStringContainsString('body.requesttoken = csrf', $js);
		self::assertStringContainsString("credentials: 'same-origin'", $js);
	}

	public function testHttp412MapsToReloadPrompt(): void
	{
		$js = $this->js();
		self::assertStringContainsString('HTTP\\s*412', $js);
		self::assertStringContainsString('Session expired. Reload the page and try again.', $js);
	}
}
