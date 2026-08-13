<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AdminTotalsService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SubsidyService;
use OCA\SnackCheck\Db\Period;
use PHPUnit\Framework\TestCase;

final class AdminTotalsServiceTest extends TestCase
{
	public function testPrivacyModeOmitsLinesAndBlocksFocus(): void
	{
		$period = new Period();
		$period->setId(1);
		$period->setLabel('2026-08');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('ensureOpenPeriod')->willReturn($period);
		$periods->method('findOpen')->willReturn($period);
		$periods->method('findLatestClosed')->willReturn(null);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isPrivacyTotalsOnly')->willReturn(true);
		$settings->method('getSubsidyAllowanceCents')->willReturn(100);
		$log = new ConsumptionLog();
		$log->setId(9);
		$log->setUserId('alice');
		$log->setUserDisplaySnap('Alice');
		$log->setItemNameSnap('Coffee');
		$log->setQty(1);
		$log->setLineTotalCents(50);
		$log->setBillingBucket('personal');
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->willReturn([$log]);
		$svc = new AdminTotalsService($mapper, $periods, $settings, new SubsidyService());
		$data = $svc->buildForOpenPeriod();
		self::assertTrue($data['privacyTotalsOnly']);
		self::assertArrayNotHasKey('lines', $data['users'][0]);
		self::assertSame(50, $data['users'][0]['grossCents']);
		$this->expectException(DomainException::class);
		$svc->buildForOpenPeriod('alice');
	}

	public function testItemizedIncludesLines(): void
	{
		$period = new Period();
		$period->setId(1);
		$period->setLabel('2026-08');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('ensureOpenPeriod')->willReturn($period);
		$periods->method('findOpen')->willReturn($period);
		$periods->method('findLatestClosed')->willReturn(null);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isPrivacyTotalsOnly')->willReturn(false);
		$settings->method('getSubsidyAllowanceCents')->willReturn(0);
		$log = new ConsumptionLog();
		$log->setId(9);
		$log->setUserId('alice');
		$log->setUserDisplaySnap('Alice');
		$log->setItemNameSnap('Coffee');
		$log->setQty(2);
		$log->setLineTotalCents(100);
		$log->setBillingBucket('personal');
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->willReturn([$log]);
		$svc = new AdminTotalsService($mapper, $periods, $settings, new SubsidyService());
		$data = $svc->buildForOpenPeriod();
		self::assertCount(1, $data['users'][0]['lines']);
		self::assertSame(100, $data['users'][0]['deductCents']);
	}
}
