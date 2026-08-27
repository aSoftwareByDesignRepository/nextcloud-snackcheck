<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\LockGate;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class CatalogCopyAndUpdateTest extends TestCase
{
	private function item(int $id, int $siteId, string $name = 'Cola'): CatalogItem
	{
		$item = new CatalogItem();
		$item->setId($id);
		$item->setSiteId($siteId);
		$item->setName($name);
		$item->setPriceCents(50);
		$item->setCategory('drink');
		$item->setActive(1);
		$item->setSortOrder(0);
		$item->setTagsJson('["vegan"]');
		return $item;
	}

	private function dbMock(): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');
		$db->method('inTransaction')->willReturn(false);
		return $db;
	}

	public function testCopyToSameSiteRejected(): void
	{
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->method('find')->willReturn($this->item(7, 1));
		$svc = new CatalogService(
			$mapper,
			$this->createMock(AuditService::class),
			$this->createMock(ITimeFactory::class),
			$this->dbMock(),
			$this->createMock(LockGate::class),
		);
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Target site must differ');
		$svc->copyToSite(7, 1, 'admin');
	}

	public function testCopyToOtherSiteCreatesWithoutStock(): void
	{
		$src = $this->item(7, 1);
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->method('find')->willReturn($src);
		$mapper->expects(self::once())->method('insert')->willReturnCallback(function (CatalogItem $item) {
			self::assertSame(2, (int)$item->getSiteId());
			self::assertSame('Cola', $item->getName());
			self::assertSame(50, (int)$item->getPriceCents());
			self::assertNull($item->getParLevel());
			self::assertNull($item->getOnHand());
			self::assertSame('["vegan"]', $item->getTagsJson());
			$item->setId(99);
			return $item;
		});
		$audit = $this->createMock(AuditService::class);
		$audit->expects(self::atLeastOnce())->method('record');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
		$svc = new CatalogService($mapper, $audit, $time, $this->dbMock(), $this->createMock(LockGate::class));
		$copy = $svc->copyToSite(7, 2, 'admin');
		self::assertSame(99, (int)$copy->getId());
	}

	public function testUpdatePriceAndPar(): void
	{
		$existing = $this->item(3, 1);
		$existing->setParLevel(5);
		$existing->setOnHand(2);
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->method('lockRow')->willReturn($existing);
		$mapper->expects(self::once())->method('update')->willReturnCallback(function (CatalogItem $item) {
			self::assertSame(120, (int)$item->getPriceCents());
			self::assertSame(10, (int)$item->getParLevel());
			self::assertSame(8, (int)$item->getOnHand());
			self::assertSame('Water', $item->getName());
			return $item;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
		$svc = new CatalogService($mapper, $this->createMock(AuditService::class), $time, $this->dbMock(), $this->createMock(LockGate::class));
		$svc->update(3, [
			'name' => 'Water',
			'priceCents' => 120,
			'parLevel' => 10,
			'onHand' => 8,
			'tags' => [],
			'active' => true,
		], 'mgr');
	}

	public function testUpdateActiveFalseDeactivates(): void
	{
		$existing = $this->item(3, 1);
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->method('lockRow')->willReturn($existing);
		$mapper->expects(self::once())->method('update')->willReturnCallback(function (CatalogItem $item) {
			self::assertSame(0, (int)$item->getActive());
			return $item;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
		$svc = new CatalogService($mapper, $this->createMock(AuditService::class), $time, $this->dbMock(), $this->createMock(LockGate::class));
		$svc->update(3, ['active' => false], 'mgr');
	}
}
