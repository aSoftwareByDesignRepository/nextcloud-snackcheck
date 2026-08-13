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
 * Hospitality/proxy actors must be able to undo within the window (loggedBy ≠ userId).
 */
final class ConsumptionLogSelfUndoTest extends TestCase
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

	public function testHospitalityActorCanSelfUndo(): void
	{
		$log = new ConsumptionLog();
		$log->setId(7);
		$log->setUserId('company');
		$log->setLoggedBy('alice');
		$log->setPeriodId(1);
		$log->setBillingBucket('company_hospitality');
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('find')->with(7)->willReturn($log);
		$mapper->method('lockRow')->with(7)->willReturn($log);
		$mapper->method('update')->willReturnCallback(static function (ConsumptionLog $row) {
			return $row;
		});
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->expects($this->once())->method('lockRow')->with(1)->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:30'))->getTimestamp());
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:30'));

		$svc = $this->service($mapper, $periodMapper, $time);
		$out = $svc->selfUndo(7, 'alice');
		self::assertNotNull($out->getVoidedAt());
		self::assertSame('alice', $out->getVoidedBy());
		self::assertSame('self-undo', $out->getVoidReason());
	}

	public function testCompanyUserCannotSelfUndoHospitalityLine(): void
	{
		$log = new ConsumptionLog();
		$log->setId(71);
		$log->setUserId('company');
		$log->setLoggedBy('alice');
		$log->setPeriodId(1);
		$log->setBillingBucket('company_hospitality');
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(71)->willReturn($log);
		$mapper->expects($this->never())->method('update');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:10'))->getTimestamp());

		$svc = $this->service($mapper, $periodMapper, $time);
		$this->expectException(DomainException::class);
		try {
			$svc->selfUndo(71, 'company');
		} catch (DomainException $e) {
			self::assertSame('permission_denied', $e->errorCode);
			self::assertSame(403, $e->httpStatus);
			throw $e;
		}
	}

	public function testProxyActorCanSelfUndo(): void
	{
		$log = new ConsumptionLog();
		$log->setId(8);
		$log->setUserId('bob');
		$log->setLoggedBy('manager');
		$log->setPeriodId(1);
		$log->setSource('admin_proxy');
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('find')->willReturn($log);
		$mapper->method('lockRow')->willReturn($log);
		$mapper->method('update')->willReturnCallback(static fn (ConsumptionLog $row) => $row);
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:10'))->getTimestamp());
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:10'));

		$svc = $this->service($mapper, $periodMapper, $time);
		$out = $svc->selfUndo(8, 'manager');
		self::assertSame('manager', $out->getVoidedBy());
	}

	public function testStrangerCannotSelfUndo(): void
	{
		$log = new ConsumptionLog();
		$log->setId(9);
		$log->setUserId('bob');
		$log->setLoggedBy('manager');
		$log->setPeriodId(1);
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('lockRow')->willReturn($log);
		$mapper->expects($this->never())->method('update');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:10'))->getTimestamp());

		$svc = $this->service($mapper, $periodMapper, $time);
		$this->expectException(DomainException::class);
		try {
			$svc->selfUndo(9, 'eve');
		} catch (DomainException $e) {
			self::assertSame('permission_denied', $e->errorCode);
			self::assertSame(403, $e->httpStatus);
			throw $e;
		}
	}

	public function testSelfUndoWindowCheckedUnderRowLock(): void
	{
		$log = new ConsumptionLog();
		$log->setId(12);
		$log->setUserId('alice');
		$log->setLoggedBy('alice');
		$log->setPeriodId(1);
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$period = new Period();
		$period->setId(1);
		$period->setState('open');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->expects($this->once())->method('lockRow')->with(12)->willReturn($log);
		$mapper->expects($this->never())->method('update');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$time = $this->createMock(ITimeFactory::class);
		// Past UNDO_SECONDS (60) while under lock.
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:02:00'))->getTimestamp());

		$svc = $this->service($mapper, $periodMapper, $time);
		try {
			$svc->selfUndo(12, 'alice');
			self::fail('expected DomainException');
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
		}

		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ConsumptionLogService.php');
		self::assertMatchesRegularExpression(
			'/if \(\$enforceSelfUndoWindow\)[\s\S]{0,800}UNDO_SECONDS/',
			$src
		);
	}

	public function testVoidDeniedWhenPeriodClosedUnderLock(): void
	{
		$log = new ConsumptionLog();
		$log->setId(10);
		$log->setUserId('alice');
		$log->setLoggedBy('alice');
		$log->setPeriodId(1);
		$log->setCreatedAt(new \DateTime('2026-08-10 12:00:00'));

		$closed = new Period();
		$closed->setId(1);
		$closed->setState('closed');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('find')->willReturn($log);
		$mapper->method('lockRow')->willReturn($log);
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($closed);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:10'))->getTimestamp());
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:10'));

		$svc = $this->service($mapper, $periodMapper, $time);
		$this->expectException(DomainException::class);
		try {
			$svc->void(10, 'alice', 'oops undo', false, true);
		} catch (DomainException $e) {
			self::assertSame('period_closed', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
			throw $e;
		}
	}

	public function testIdempotencyConflictWhenActorDiffers(): void
	{
		$existing = new ConsumptionLog();
		$existing->setId(42);
		$existing->setItemId(10);
		$existing->setQty(1);
		$existing->setSiteId(1);
		$existing->setBillingBucket('personal');
		$existing->setSource('web');
		$existing->setUserId('alice');
		$existing->setLoggedBy('alice');
		$existing->setCreatedAt(new \DateTime('2026-08-10 11:59:00'));

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn($existing);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:00'))->getTimestamp());

		$svc = new ConsumptionLogService(
			$mapper,
			$this->createMock(PeriodMapper::class),
			$this->createMock(CatalogService::class),
			$this->createMock(PeriodService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(IDBConnection::class),
			$time,
			$this->createMock(IUserManager::class),
		);

		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k-actor', 'siteId' => 1,
				'actorUserId' => 'mallory', 'source' => 'web', 'mode' => 'self',
			]);
		} catch (DomainException $e) {
			self::assertSame('idempotency_conflict', $e->errorCode);
			throw $e;
		}
	}
}
