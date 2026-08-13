<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItem;
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
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class ConsumptionLogIdempotencyTest extends TestCase
{
	private function makeService(
		?ConsumptionLog $existing = null,
		bool $periodOpen = true,
		bool $itemActive = true,
	): ConsumptionLogService {
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn($existing);

		$period = new Period();
		$period->setId(1);
		$period->setState($periodOpen ? 'open' : 'closed');
		$period->setLabel('2026-08');

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($periodOpen ? $period : null);
		$periodMapper->method('find')->willReturn($period);

		$item = new CatalogItem();
		$item->setId(10);
		$item->setSiteId(1);
		$item->setName('Kaffee');
		$item->setPriceCents(50);
		$item->setActive($itemActive ? 1 : 0);

		$catalog = $this->createMock(CatalogService::class);
		$catalog->method('getForUpdate')->willReturn($item);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isHospitalityEnabled')->willReturn(true);
		$settings->method('getHospitalityCompanyUserId')->willReturn('company');

		$audit = $this->createMock(AuditService::class);
		$hosp = $this->createMock(HospAllowMapper::class);
		$hosp->method('isAllowed')->willReturn(true);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:00'));
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:00'))->getTimestamp());

		$users = $this->directoryUsers();

		if ($existing === null) {
			$mapper->method('insert')->willReturnCallback(static function (ConsumptionLog $log): ConsumptionLog {
				$log->setId(99);
				return $log;
			});
		}

		return new ConsumptionLogService(
			$mapper, $periodMapper, $catalog, $periods, $settings, $audit, $hosp,
			$this->createMock(AccessControlService::class),
			$db, $time, $users
		);
	}

	/** Directory UIDs exist for hospitality/proxy paths (ghost-UID gate). */
	private function directoryUsers(): IUserManager
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) {
			if ($uid === '') {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($uid);
			return $user;
		});
		return $users;
	}

	public function testIdempotentReplayReturns200SameId(): void
	{
		$existing = new ConsumptionLog();
		$existing->setId(42);
		$existing->setItemId(10);
		$existing->setQty(1);
		$existing->setSiteId(1);
		$existing->setBillingBucket('personal');
		$existing->setSource('web');
		$existing->setUserId('alice');
		$existing->setProxyReason(null);
		$existing->setHospReason(null);
		$existing->setCreatedAt(new \DateTime('2026-08-10 11:59:00'));

		$svc = $this->makeService($existing);
		$result = $svc->create([
			'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k1', 'siteId' => 1,
			'actorUserId' => 'alice', 'source' => 'web', 'mode' => 'self',
		]);
		self::assertTrue($result['replay']);
		self::assertSame(200, $result['httpStatus']);
		self::assertSame(42, $result['log']->getId());
	}

	public function testIdempotencyConflictOnBodyChange(): void
	{
		$existing = new ConsumptionLog();
		$existing->setId(42);
		$existing->setItemId(10);
		$existing->setQty(1);
		$existing->setSiteId(1);
		$existing->setBillingBucket('personal');
		$existing->setSource('web');
		$existing->setUserId('alice');
		$existing->setCreatedAt(new \DateTime('2026-08-10 11:59:00'));

		$svc = $this->makeService($existing);
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 2, 'idempotencyKey' => 'k1', 'siteId' => 1,
				'actorUserId' => 'alice', 'source' => 'web', 'mode' => 'self',
			]);
		} catch (DomainException $e) {
			self::assertSame('idempotency_conflict', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
			throw $e;
		}
	}

	public function testPeriodClosed(): void
	{
		$svc = $this->makeService(null, false);
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k2', 'siteId' => 1,
				'actorUserId' => 'alice', 'source' => 'web', 'mode' => 'self',
			]);
		} catch (DomainException $e) {
			self::assertSame('period_closed', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
			throw $e;
		}
	}

	public function testInactiveItem(): void
	{
		$svc = $this->makeService(null, true, false);
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k3', 'siteId' => 1,
				'actorUserId' => 'alice', 'source' => 'web', 'mode' => 'self',
			]);
		} catch (DomainException $e) {
			self::assertSame('item_inactive', $e->errorCode);
			throw $e;
		}
	}

	public function testProxyRequiresKitchenAdmin(): void
	{
		$svc = $this->makeService();
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k4', 'siteId' => 1,
				'actorUserId' => 'alice', 'source' => 'terminal', 'mode' => 'proxy',
				'targetUserId' => 'bob', 'proxyReason' => 'forgot phone',
				'isKitchenAdmin' => false,
			]);
		} catch (DomainException $e) {
			self::assertSame('permission_denied', $e->errorCode);
			throw $e;
		}
	}

	public function testProxyRequiresReason(): void
	{
		$svc = $this->makeService();
		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k5', 'siteId' => 1,
				'actorUserId' => 'admin', 'source' => 'terminal', 'mode' => 'proxy',
				'targetUserId' => 'bob', 'proxyReason' => 'ab',
				'isKitchenAdmin' => true,
			]);
		} catch (DomainException $e) {
			self::assertSame('proxy_reason_required', $e->errorCode);
			throw $e;
		}
	}

	public function testHospitalitySetsCompanyBucket(): void
	{
		$svc = $this->makeService();
		$result = $svc->create([
			'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'k6', 'siteId' => 1,
			'actorUserId' => 'alice', 'source' => 'terminal', 'mode' => 'hospitality',
			'hospitalityReason' => 'guest visit',
			'isKitchenAdmin' => false,
		]);
		self::assertFalse($result['replay']);
		self::assertSame(201, $result['httpStatus']);
		self::assertSame('company_hospitality', $result['log']->getBillingBucket());
		self::assertSame('company', $result['log']->getUserId());
	}

	public function testCreateDoesNotTouchOnHand(): void
	{
		// Documented invariant: CatalogService::get is used; restock/setOnHand never called.
		$catalog = $this->createMock(CatalogService::class);
		$item = new CatalogItem();
		$item->setId(10); $item->setSiteId(1); $item->setName('Kaffee'); $item->setPriceCents(50); $item->setActive(1);
		$catalog->expects($this->once())->method('getForUpdate')->willReturn($item);
		$catalog->expects($this->never())->method('restock');
		$catalog->expects($this->never())->method('setOnHand');

		$period = new Period(); $period->setId(1); $period->setState('open'); $period->setLabel('2026-08');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn(null);
		$created = new ConsumptionLog();
		$created->setId(1); $created->setBillingBucket('personal'); $created->setUserId('alice');
		$created->setLineTotalCents(50); $created->setItemId(10); $created->setQty(1); $created->setSiteId(1);
		$created->setCreatedAt(new \DateTime());
		$mapper->method('insert')->willReturn($created);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$time->method('getTime')->willReturn(time());

		$svc = new ConsumptionLogService(
			$mapper, $periodMapper, $catalog, $periods,
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$this->createMock(AccessControlService::class),
			$db, $time, $this->createMock(IUserManager::class),
		);
		$svc->create([
			'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'no-stock', 'siteId' => 1,
			'actorUserId' => 'alice', 'source' => 'web', 'mode' => 'self',
		]);
	}

	public function testProxyTargetMustPassAccessDoor(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->with('outsider')->willReturn(false);

		$period = new Period();
		$period->setId(1);
		$period->setState('open');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);

		$item = new CatalogItem();
		$item->setId(10);
		$item->setName('Cola');
		$item->setPriceCents(100);
		$item->setActive(1);
		$item->setSiteId(1);
		$catalog = $this->createMock(CatalogService::class);
		$catalog->method('getForUpdate')->willReturn($item);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn(null);
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('rollBack');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$time->method('getTime')->willReturn(time());

		$svc = new ConsumptionLogService(
			$mapper, $periodMapper, $catalog, $periods,
			$this->createMock(SettingsService::class),
			$this->createMock(AuditService::class),
			$this->createMock(HospAllowMapper::class),
			$access, $db, $time, $this->directoryUsers(),
		);

		$this->expectException(DomainException::class);
		try {
			$svc->create([
				'itemId' => 10, 'qty' => 1, 'idempotencyKey' => 'proxy-denied', 'siteId' => 1,
				'actorUserId' => 'admin', 'source' => 'web', 'mode' => 'proxy',
				'targetUserId' => 'outsider', 'proxyReason' => 'forgot badge',
				'isKitchenAdmin' => true,
			]);
		} catch (DomainException $e) {
			self::assertSame('permission_denied', $e->errorCode);
			self::assertSame(403, $e->httpStatus);
			throw $e;
		}
	}
}
