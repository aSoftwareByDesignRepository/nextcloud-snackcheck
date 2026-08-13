<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Exception\DomainException;
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
 * Argus MF: tablet self-undo must bind to device siteId under the same FOR UPDATE lock.
 */
final class DeviceUndoSiteBindTest extends TestCase
{
	private function service(
		ConsumptionLogMapper $mapper,
		PeriodMapper $periodMapper,
		ITimeFactory $time,
	): ConsumptionLogService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');
		return new ConsumptionLogService(
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
	}

	public function testSelfUndoAllowsMatchingSite(): void
	{
		$log = new ConsumptionLog();
		$log->setId(41);
		$log->setUserId('alice');
		$log->setSiteId(3);
		$log->setPeriodId(1);
		$log->setBillingBucket('personal');
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('lockRow')->with(41)->willReturn($log);
		$mapper->method('update')->willReturnCallback(static fn (ConsumptionLog $row) => $row);
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->expects($this->once())->method('lockRow')->with(1)->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:20'))->getTimestamp());
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:20'));

		$out = $this->service($mapper, $periodMapper, $time)->selfUndo(41, 'alice', 3);
		self::assertSame(41, $out->getId());
		self::assertNotNull($out->getVoidedAt());
	}

	public function testSelfUndoRejectsForeignSiteUnderLock(): void
	{
		$log = new ConsumptionLog();
		$log->setId(42);
		$log->setUserId('alice');
		$log->setSiteId(9);
		$log->setPeriodId(1);
		$log->setBillingBucket('personal');
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('lockRow')->with(42)->willReturn($log);
		$mapper->expects($this->never())->method('update');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->expects($this->once())->method('lockRow')->with(1)->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:20'))->getTimestamp());

		$svc = $this->service($mapper, $periodMapper, $time);
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Log is not for this site');
		$svc->selfUndo(42, 'alice', 3);
	}

	public function testDeviceApiPassesDeviceSiteId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertMatchesRegularExpression(
			'/function undoLog[\s\S]{0,600}selfUndo\(\$id,\s*\$session\[[\'"]userId[\'"]\],\s*\(int\)\$device->getSiteId\(\)\)/',
			$src
		);
	}
}
