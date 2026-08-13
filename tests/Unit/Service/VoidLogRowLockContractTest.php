<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

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
 * Concurrent void/undo must lock the log row (FOR UPDATE) before mutating.
 */
final class VoidLogRowLockContractTest extends TestCase
{
	public function testVoidLocksLogRowBeforePeriod(): void
	{
		$log = new ConsumptionLog();
		$log->setId(42);
		$log->setUserId('alice');
		$log->setLoggedBy('alice');
		$log->setPeriodId(9);
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(9);
		$period->setState('open');

		$order = [];
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(42)->willReturnCallback(
			static function () use (&$order, $log) {
				$order[] = 'log';
				return $log;
			}
		);
		$mapper->expects($this->never())->method('find');
		$mapper->method('update')->willReturnCallback(static fn (ConsumptionLog $row) => $row);

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->expects($this->once())->method('lockRow')->with(9)->willReturnCallback(
			static function () use (&$order, $period) {
				$order[] = 'period';
				return $period;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:30'));

		$svc = new ConsumptionLogService(
			$mapper,
			$periodMapper,
			$this->createMock(CatalogService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$this->createMock(AccessControlService::class),
			$db,
			$time,
			$this->createMock(IUserManager::class),
		);

		$out = $svc->void(42, 'alice', 'double tap', false, true);
		self::assertSame(['log', 'period'], $order);
		self::assertNotNull($out->getVoidedAt());
	}

	public function testVoidIdempotentWhenAlreadyVoidedUnderLock(): void
	{
		$log = new ConsumptionLog();
		$log->setId(43);
		$log->setUserId('alice');
		$log->setVoidedAt(new \DateTime('2026-08-10 11:00:00'));
		$log->setVoidedBy('alice');
		$log->setVoidReason('earlier');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('lockRow')->willReturn($log);
		$mapper->expects($this->never())->method('update');

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->expects($this->never())->method('lockRow');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('commit');

		$svc = new ConsumptionLogService(
			$mapper,
			$periodMapper,
			$this->createMock(CatalogService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$this->createMock(AccessControlService::class),
			$db,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
		);

		$out = $svc->void(43, 'alice', 'again please', false, true);
		self::assertSame('earlier', $out->getVoidReason());
	}

	public function testAdminVoidEnforcesSiteAclUnderRowLock(): void
	{
		$log = new ConsumptionLog();
		$log->setId(44);
		$log->setUserId('bob');
		$log->setSiteId(7);
		$log->setPeriodId(9);
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(9);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(44)->willReturn($log);
		$mapper->expects($this->never())->method('find');
		$mapper->method('update')->willReturnCallback(static fn (ConsumptionLog $row) => $row);

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('canManageSite')->with('mgr', 7)->willReturn(true);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('commit');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:30'));

		$svc = new ConsumptionLogService(
			$mapper,
			$periodMapper,
			$this->createMock(CatalogService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$access,
			$db,
			$time,
			$this->createMock(IUserManager::class),
		);

		$out = $svc->void(44, 'mgr', 'wrong charge', true);
		self::assertNotNull($out->getVoidedAt());
	}

	public function testAdminVoidRejectsForeignSiteUnderLock(): void
	{
		$log = new ConsumptionLog();
		$log->setId(45);
		$log->setUserId('bob');
		$log->setSiteId(99);
		$log->setPeriodId(9);

		$period = new Period();
		$period->setId(9);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('lockRow')->willReturn($log);
		$mapper->expects($this->never())->method('update');

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canManageSite')->with('mgr', 99)->willReturn(false);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$svc = new ConsumptionLogService(
			$mapper,
			$periodMapper,
			$this->createMock(CatalogService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$access,
			$db,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserManager::class),
		);

		$this->expectException(\OCA\SnackCheck\Exception\DomainException::class);
		$this->expectExceptionMessage('Site not allowed for this manager');
		$svc->void(45, 'mgr', 'wrong site', true);
	}
}
