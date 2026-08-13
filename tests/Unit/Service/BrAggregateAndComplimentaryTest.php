<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Service\BrAggregateService;
use OCA\SnackCheck\Service\ComplimentaryExportService;
use OCA\SnackCheck\Service\PeriodService;
use PHPUnit\Framework\TestCase;

final class BrAggregateServiceTest extends TestCase
{
	public function testNoUserColumnsAndAggregates(): void
	{
		$period = new Period();
		$period->setId(5);
		$period->setLabel('2026-08');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('get')->willReturn($period);

		$log1 = new ConsumptionLog();
		$log1->setItemId(1);
		$log1->setItemNameSnap('Kaffee');
		$log1->setQty(2);
		$log1->setLineTotalCents(100);
		$log1->setUserId('alice');
		$log2 = new ConsumptionLog();
		$log2->setItemId(1);
		$log2->setItemNameSnap('Kaffee');
		$log2->setQty(1);
		$log2->setLineTotalCents(50);
		$log2->setUserId('bob');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->willReturn([$log1, $log2]);
		$item = new CatalogItem();
		$item->setName('Kaffee');
		$item->setCategory('drink');
		$catalog = $this->createMock(CatalogItemMapper::class);
		$catalog->method('find')->willReturn($item);

		$svc = new BrAggregateService($mapper, $catalog, $periods);
		$data = $svc->buildForPeriod(5);
		$json = json_encode($data, JSON_THROW_ON_ERROR);
		foreach (BrAggregateService::forbiddenColumns() as $col) {
			self::assertStringNotContainsString('"' . $col . '"', $json);
		}
		self::assertStringNotContainsString('alice', $json);
		self::assertStringNotContainsString('bob', $json);
		self::assertSame(3, $data['byItem'][0]['qty']);
		self::assertSame('drink', $data['byItem'][0]['category']);
		self::assertSame(3, $data['byCategory'][0]['qty']);
	}
}

final class ComplimentaryExportServiceTest extends TestCase
{
	public function testOnlyFreeLines(): void
	{
		$period = new Period();
		$period->setId(1);
		$period->setLabel('2026-08');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('get')->willReturn($period);
		$free = new ConsumptionLog();
		$free->setItemNameSnap('Obst');
		$free->setQty(4);
		$free->setLineTotalCents(0);
		$paid = new ConsumptionLog();
		$paid->setItemNameSnap('Cola');
		$paid->setQty(1);
		$paid->setLineTotalCents(100);
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->willReturn([$free, $paid]);
		$svc = new ComplimentaryExportService($mapper, $periods);
		$rows = $svc->buildRows(1);
		self::assertCount(1, $rows);
		self::assertSame('Obst', $rows[0]['item_name']);
		self::assertSame(4, $rows[0]['qty']);
		$csv = $svc->toCsv(1);
		self::assertStringContainsString('Obst', $csv);
		self::assertStringNotContainsString('Cola', $csv);
	}
}
