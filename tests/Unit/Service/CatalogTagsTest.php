<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class CatalogTagsTest extends TestCase
{
	private function service(): CatalogService
	{
		return new CatalogService(
			$this->createMock(CatalogItemMapper::class),
			$this->createMock(AuditService::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testNormalizeAllowsKnownTagsAndDedupes(): void
	{
		$svc = $this->service();
		self::assertSame(
			['vegan', 'gluten_free'],
			$svc->normalizeTags(['vegan', 'VEGAN', 'gluten_free', 'unknown_junk'])
		);
	}

	public function testNormalizeNullStaysNull(): void
	{
		self::assertNull($this->service()->normalizeTags(null));
	}

	public function testNormalizeEmptyArray(): void
	{
		self::assertSame([], $this->service()->normalizeTags([]));
	}

	public function testAllowedTagsCoverSpec(): void
	{
		foreach (['vegan', 'vegetarian', 'gluten_free', 'contains_alcohol'] as $tag) {
			self::assertContains($tag, CatalogService::ALLOWED_TAGS);
		}
	}
}
