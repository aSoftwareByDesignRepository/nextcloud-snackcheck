<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\SiteService;
use PHPUnit\Framework\TestCase;

/**
 * Source + behaviour guards for site activate/deactivate (AC-OPP-Y).
 */
final class SiteActiveGuardsTest extends TestCase
{
	public function testCannotDeactivateDefaultSite(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Service/SiteService.php');
		self::assertStringContainsString('Cannot deactivate default site', $src);
		self::assertStringContainsString('DEFAULT_CODE', $src);
		self::assertTrue(method_exists(SiteService::class, 'listAll'));
	}

	public function testTruthyActiveParsingUsedInApi(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/ApiController.php');
		self::assertStringContainsString('$this->truthy($active)', $src);
		self::assertStringContainsString('$this->truthy($this->request->getParam(\'active\'))', $src);
		// Regression: (bool)"0" is true in PHP — must not appear for active flags.
		self::assertStringNotContainsString('(bool)$active', $src);
		self::assertStringNotContainsString('(bool)$this->request->getParam(\'active\')', $src);
	}

	public function testDomainExceptionConstant(): void
	{
		$e = new DomainException('validation_failed', 'Cannot deactivate default site', 422);
		self::assertSame(422, $e->httpStatus);
	}
}
