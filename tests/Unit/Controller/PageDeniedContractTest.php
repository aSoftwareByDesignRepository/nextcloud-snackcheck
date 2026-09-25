<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Gated page routes must render a clean 403/404 denied template — an uncaught
 * DomainException bubbles to the dispatcher and surfaces as a raw HTTP 500
 * (Atlas web_api probe 20260923: denied/listed users got 500 on /sites, /catalog, …).
 */
final class PageDeniedContractTest extends TestCase
{
	private function src(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
	}

	public function testNoRawAssertCallsRemainOnPageRoutes(): void
	{
		$src = $this->src();
		// These throw DomainException; page routes must convert to denied() instead.
		self::assertStringNotContainsString('$this->access->assertAccess(', $src);
		self::assertStringNotContainsString('$this->access->assertAppAdmin(', $src);
		self::assertStringNotContainsString('$this->assertManager(', $src);
	}

	public function testNoDomainExceptionRethrowRemains(): void
	{
		self::assertStringNotContainsString('throw $e;', $this->src());
	}

	public function testDeniedHelperSetsHttpStatusAndTemplate(): void
	{
		$src = $this->src();
		self::assertMatchesRegularExpression(
			'/function denied\(string \$reason, int \$status = Http::STATUS_FORBIDDEN\): TemplateResponse/',
			$src
		);
		self::assertStringContainsString("new TemplateResponse('snackcheck', 'denied'", $src);
		self::assertStringContainsString('setStatus($status)', $src);
		self::assertFileExists(dirname(__DIR__, 3) . '/templates/denied.php');
	}

	public function testDeniedTemplateIsLocalisedAndHasRecoveryLink(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/denied.php');
		self::assertStringContainsString('deniedMessage', $tpl);
		self::assertStringContainsString('backUrl', $tpl);
		self::assertStringContainsString('backLabel', $tpl);
		self::assertStringContainsString('$l->t(', $tpl);
	}
}
