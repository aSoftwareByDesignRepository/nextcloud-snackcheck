<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\LockGate;
use OCA\SnackCheck\Db\TerminalDevice;
use OCA\SnackCheck\Db\TerminalDeviceMapper;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TerminalDeviceServiceTest extends TestCase
{
	public function testRegisterUsesDbCapacityGateAndRespectsLimit(): void
	{
		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('countActive')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$license->method('getTerminalDeviceLimit')->willReturn(1);

		$lockGate = $this->createMock(LockGate::class);
		$lockGate->expects($this->once())->method('lock')
			->with(TerminalDeviceService::CAPACITY_LOCK);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$svc = new TerminalDeviceService(
			$mapper, $license, $db, $lockGate,
			$this->createMock(ITimeFactory::class),
		);
		$result = $svc->register('admin', 'Kitchen', 1);
		self::assertFalse($result['ok']);
		self::assertSame('terminal_limit_reached', $result['error']);
	}

	public function testTrimRevokesNewestOverflowUnderDbGate(): void
	{
		$d1 = new TerminalDevice(); $d1->setId(1); $d1->setRevoked(0);
		$d2 = new TerminalDevice(); $d2->setId(2); $d2->setRevoked(0);
		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('findAllActiveOrdered')->willReturn([$d1, $d2]);
		$mapper->expects($this->once())->method('update')->with($this->callback(function ($d) {
			return $d->getId() === 2 && $d->getRevoked() === 1;
		}));
		$lockGate = $this->createMock(LockGate::class);
		$lockGate->expects($this->once())->method('lock')
			->with(TerminalDeviceService::CAPACITY_LOCK);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$svc = new TerminalDeviceService(
			$mapper,
			$this->createMock(LicenseService::class),
			$db,
			$lockGate,
			$time,
		);
		self::assertSame(1, $svc->trimToLimit(1));
	}

	public function testCapacityLockConstantIsDbGateKey(): void
	{
		self::assertSame(LockGate::KEY_TERMINAL_CAPACITY, TerminalDeviceService::CAPACITY_LOCK);
		self::assertSame('terminal_capacity', TerminalDeviceService::CAPACITY_LOCK);
	}

	public function testResolveTokenRequiresPrefix(): void
	{
		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->expects($this->never())->method('findActiveByTokenHash');
		$svc = new TerminalDeviceService(
			$mapper,
			$this->createMock(LicenseService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LockGate::class),
			$this->createMock(ITimeFactory::class),
		);
		self::assertNull($svc->resolveToken('Bearer dkterm_abc'));
	}

	public function testRevokeMarksDeviceInactiveUnderDbGate(): void
	{
		$device = new TerminalDevice();
		$device->setId(9);
		$device->setRevoked(0);
		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->expects($this->once())->method('findActiveById')->with(9)->willReturn($device);
		$mapper->expects($this->once())->method('update')->with($this->callback(static function (TerminalDevice $d): bool {
			return $d->getId() === 9 && $d->getRevoked() === 1;
		}));
		$lockGate = $this->createMock(LockGate::class);
		$lockGate->expects($this->once())->method('lock')->with(TerminalDeviceService::CAPACITY_LOCK);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$svc = new TerminalDeviceService(
			$mapper,
			$this->createMock(LicenseService::class),
			$db,
			$lockGate,
			$this->createMock(ITimeFactory::class),
		);
		$result = $svc->revoke(9, 'admin');
		self::assertTrue($result['ok']);
	}

	public function testRevokeMissingDeviceFailsClosed(): void
	{
		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('findActiveById')->willReturn(null);
		$lockGate = $this->createMock(LockGate::class);
		$lockGate->expects($this->once())->method('lock')->with(TerminalDeviceService::CAPACITY_LOCK);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$svc = new TerminalDeviceService(
			$mapper,
			$this->createMock(LicenseService::class),
			$db,
			$lockGate,
			$this->createMock(ITimeFactory::class),
		);
		$result = $svc->revoke(404, 'admin');
		self::assertFalse($result['ok']);
		self::assertSame('terminal_not_found', $result['error']);
	}

	public function testRevokeAllBySiteRevokesUnderCapacityLock(): void
	{
		$a = new TerminalDevice();
		$a->setId(1);
		$a->setSiteId(3);
		$a->setRevoked(0);
		$b = new TerminalDevice();
		$b->setId(2);
		$b->setSiteId(3);
		$b->setRevoked(0);
		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('findActiveBySite')->with(3)->willReturn([$a, $b]);
		$mapper->expects($this->exactly(2))->method('update')->willReturnCallback(
			static function (TerminalDevice $d): TerminalDevice {
				self::assertSame(1, (int)$d->getRevoked());
				return $d;
			}
		);
		$lockGate = $this->createMock(LockGate::class);
		$lockGate->expects($this->once())->method('lock')->with(TerminalDeviceService::CAPACITY_LOCK);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$svc = new TerminalDeviceService(
			$mapper,
			$this->createMock(LicenseService::class),
			$db,
			$lockGate,
			$this->createMock(ITimeFactory::class),
		);
		self::assertSame(2, $svc->revokeAllBySite(3, 'site-deactivate:3'));
	}
}
