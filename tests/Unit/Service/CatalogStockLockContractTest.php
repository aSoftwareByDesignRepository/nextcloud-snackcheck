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

/**
 * Aristoteles: restock/setOnHand/softDelete must RMW under FOR UPDATE (no lost stock updates).
 */
final class CatalogStockLockContractTest extends TestCase
{
	private function item(int $onHand): CatalogItem
	{
		$item = new CatalogItem();
		$item->setId(42);
		$item->setSiteId(1);
		$item->setName('Cola');
		$item->setPriceCents(100);
		$item->setCategory('drink');
		$item->setActive(1);
		$item->setOnHand($onHand);
		return $item;
	}

	private function dbExpectingTxn(): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('commit');
		$db->expects(self::never())->method('rollBack');
		$db->method('inTransaction')->willReturn(false);
		return $db;
	}

	public function testRestockUsesLockRowAndAddsQty(): void
	{
		$existing = $this->item(10);
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->expects(self::once())->method('lockRow')->with(42)->willReturn($existing);
		$mapper->expects(self::never())->method('find');
		$mapper->expects(self::once())->method('update')->willReturnCallback(function (CatalogItem $item) {
			self::assertSame(15, (int)$item->getOnHand());
			return $item;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
		$audit = $this->createMock(AuditService::class);
		$audit->expects(self::once())->method('record')->with('admin', 'catalog.restock', 'catalog_item', '42', ['add' => 5]);

		$svc = new CatalogService($mapper, $audit, $time, $this->dbExpectingTxn(), $this->createMock(LockGate::class));
		$out = $svc->restock(42, 5, 'admin');
		self::assertSame(15, (int)$out->getOnHand());
	}

	public function testRestockRejectsZeroQtyBeforeLock(): void
	{
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->expects(self::never())->method('lockRow');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');
		$svc = new CatalogService(
			$mapper,
			$this->createMock(AuditService::class),
			$this->createMock(ITimeFactory::class),
			$db,
			$this->createMock(LockGate::class),
		);
		$this->expectException(DomainException::class);
		$svc->restock(42, 0, 'admin');
	}

	public function testSoftDeleteLocksThenDeactivates(): void
	{
		$existing = $this->item(3);
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->expects(self::once())->method('lockRow')->with(42)->willReturn($existing);
		$mapper->expects(self::once())->method('update')->willReturnCallback(function (CatalogItem $item) {
			self::assertSame(0, (int)$item->getActive());
			return $item;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
		$svc = new CatalogService(
			$mapper,
			$this->createMock(AuditService::class),
			$time,
			$this->dbExpectingTxn(),
			$this->createMock(LockGate::class),
		);
		$out = $svc->softDelete(42, 'admin');
		self::assertSame(0, (int)$out->getActive());
	}

	public function testSetOnHandLocksRow(): void
	{
		$existing = $this->item(7);
		$mapper = $this->createMock(CatalogItemMapper::class);
		$mapper->expects(self::once())->method('lockRow')->with(42)->willReturn($existing);
		$mapper->expects(self::once())->method('update')->willReturnCallback(function (CatalogItem $item) {
			self::assertSame(20, (int)$item->getOnHand());
			return $item;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
		$svc = new CatalogService(
			$mapper,
			$this->createMock(AuditService::class),
			$time,
			$this->dbExpectingTxn(),
			$this->createMock(LockGate::class),
		);
		self::assertSame(20, (int)$svc->setOnHand(42, 20, 'admin')->getOnHand());
	}

	public function testSourceRequiresMutateLockedHelper(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CatalogService.php');
		self::assertStringContainsString('function mutateLocked(int $id, callable $mutator)', $src);
		self::assertMatchesRegularExpression(
			'/function restock\([\s\S]*?mutateLocked\(/m',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function softDelete\([\s\S]*?mutateLocked\(/m',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function setOnHand\([\s\S]*?mutateLocked\(/m',
			$src
		);
		self::assertMatchesRegularExpression(
			'/function updatePrice\([\s\S]*?mutateLocked\(/m',
			$src
		);
	}
}
