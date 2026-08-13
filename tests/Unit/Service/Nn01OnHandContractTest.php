<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * NN-01 / CORE §10.5: logging never mutates on_hand (InventoryCheck is out).
 */
final class Nn01OnHandContractTest extends TestCase
{
	public function testCreateDoesNotCallRestockOrSetOnHand(): void
	{
		$item = new CatalogItem();
		$item->setId(10);
		$item->setSiteId(1);
		$item->setName('Kaffee');
		$item->setPriceCents(50);
		$item->setActive(1);
		$item->setOnHand(20);

		$catalog = $this->createMock(CatalogService::class);
		$catalog->expects($this->atLeastOnce())->method('getForUpdate')->with(10)->willReturn($item);
		$catalog->expects($this->never())->method('restock');
		$catalog->expects($this->never())->method('setOnHand');

		$period = new Period();
		$period->setId(1);
		$period->setState('open');
		$period->setLabel('2026-08');

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn(null);
		$mapper->method('insert')->willReturnCallback(static function (ConsumptionLog $log): ConsumptionLog {
			$log->setId(77);
			return $log;
		});

		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:00'));
		$time->method('getTime')->willReturn(1);

		$svc = new ConsumptionLogService(
			$mapper,
			$periodMapper,
			$catalog,
			$periods,
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$this->createMock(AccessControlService::class),
			$db,
			$time,
			$this->createMock(IUserManager::class),
		);

		$result = $svc->create([
			'itemId' => 10,
			'qty' => 2,
			'idempotencyKey' => 'nn01',
			'siteId' => 1,
			'actorUserId' => 'alice',
			'source' => 'terminal',
			'mode' => 'self',
		]);

		self::assertFalse($result['replay']);
		self::assertSame(201, $result['httpStatus']);
		self::assertSame(20, $item->getOnHand());
	}

	public function testSourceDocumentsNn01Intentionally(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ConsumptionLogService.php');
		self::assertStringContainsString('NN-01: intentionally do NOT touch on_hand', $src);
		self::assertDoesNotMatchRegularExpression('/->restock\(/', $src);
		self::assertDoesNotMatchRegularExpression('/->setOnHand\(/', $src);
	}
}
