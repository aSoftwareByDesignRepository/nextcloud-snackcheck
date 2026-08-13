<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use OCA\SnackCheck\Controller\ApiController;
use OCA\SnackCheck\Exception\DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Behavioral euro/cents parsing — catches silent clamp / scientific notation mutations.
 */
final class SubsidyAmountParseTest extends TestCase
{
	private function parse(string $raw): int
	{
		$ref = new ReflectionMethod(ApiController::class, 'parseEuroToCents');
		$ref->setAccessible(true);
		/** @var ApiController $dummy */
		$dummy = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
		return (int)$ref->invoke($dummy, $raw);
	}

	public function testParsesCommaDecimal(): void
	{
		self::assertSame(1250, $this->parse('12,50'));
	}

	public function testParsesDotDecimal(): void
	{
		self::assertSame(99, $this->parse('0.99'));
	}

	public function testRejectsScientificNotation(): void
	{
		$this->expectException(DomainException::class);
		try {
			$this->parse('1e2');
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
			throw $e;
		}
	}

	public function testRejectsNegativeEuro(): void
	{
		$this->expectException(DomainException::class);
		$this->parse('-1.00');
	}

	public function testSaveSettingsRejectsNegativeCentsBeforeWrite(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('Invalid subsidy amount', $src);
		self::assertMatchesRegularExpression(
			'/subsidyAllowanceCents[\s\S]{0,400}\$cents < 0/',
			$src
		);
		self::assertStringNotContainsString('Final invariant (defense in depth after apply)', $src);
		self::assertStringContainsString('never 422 after writes', $src);
		self::assertStringContainsString('copyBtn.focus()', (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js'));
	}

	public function testCatalogLockRowExistsForCreatePath(): void
	{
		$mapper = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/CatalogItemMapper.php');
		self::assertStringContainsString('function lockRow(int $id)', $mapper);
		self::assertStringContainsString('FOR UPDATE', $mapper);
		$svc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CatalogService.php');
		self::assertStringContainsString('function getForUpdate(int $id)', $svc);
		self::assertStringContainsString('function mutateLocked(int $id, callable $mutator)', $svc);
	}

	public function testClientEuroParseRejectsScientificNotation(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertStringContainsString('function parseEuroToCentsClient(raw)', $js);
		self::assertStringContainsString('/[eE]/.test(normalized)', $js);
		self::assertStringContainsString('parseEuroToCentsClient(body.subsidyAllowanceEuro)', $js);
		self::assertDoesNotMatchRegularExpression(
			'/subsidyAllowanceEuro[\s\S]{0,120}parseFloat\(/',
			$js
		);
	}
}
