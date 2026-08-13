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

/**
 * Zeus MF: long-lived snkterm_ Bearers must fail closed after idle expiry.
 */
final class TerminalDeviceIdleExpiryTest extends TestCase
{
	private function service(
		TerminalDeviceMapper $mapper,
		ITimeFactory $time,
	): TerminalDeviceService {
		return new TerminalDeviceService(
			$mapper,
			$this->createMock(LicenseService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LockGate::class),
			$time,
		);
	}

	public function testResolveRejectsDeviceIdleBeyondMax(): void
	{
		$plain = TerminalDeviceService::TOKEN_PREFIX . str_repeat('ab', 32);
		$device = new TerminalDevice();
		$device->setId(1);
		$device->setTokenHash(hash('sha256', $plain));
		$device->setLastSeenAt(new \DateTime('@1000'));
		$device->setRegisteredAt(new \DateTime('@1000'));
		$device->setRevoked(0);

		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('findActiveByTokenHash')->willReturn($device);
		$mapper->expects($this->never())->method('update');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(
			new \DateTime('@' . (1000 + TerminalDeviceService::MAX_IDLE_SECONDS + 1))
		);

		$svc = $this->service($mapper, $time);
		self::assertNull($svc->resolveToken('Bearer ' . $plain));
	}

	public function testResolveAcceptsRecentlySeenDeviceAndTouches(): void
	{
		$plain = TerminalDeviceService::TOKEN_PREFIX . str_repeat('cd', 32);
		$device = new TerminalDevice();
		$device->setId(2);
		$device->setTokenHash(hash('sha256', $plain));
		$device->setLastSeenAt(new \DateTime('@1700000000'));
		$device->setRegisteredAt(new \DateTime('@1700000000'));
		$device->setRevoked(0);

		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('findActiveByTokenHash')->willReturn($device);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static fn (TerminalDevice $d) => $d
		);

		$now = new \DateTime('@' . (1700000000 + 120));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($now);

		$svc = $this->service($mapper, $time);
		$out = $svc->resolveToken('Bearer ' . $plain);
		self::assertNotNull($out);
		self::assertSame(2, $out->getId());
		self::assertEquals($now->getTimestamp(), $out->getLastSeenAt()?->getTimestamp());
	}

	public function testResolveUsesRegisteredAtWhenNeverSeen(): void
	{
		$plain = TerminalDeviceService::TOKEN_PREFIX . str_repeat('ef', 32);
		$device = new TerminalDevice();
		$device->setId(3);
		$device->setTokenHash(hash('sha256', $plain));
		$device->setLastSeenAt(null);
		$device->setRegisteredAt(new \DateTime('@500'));
		$device->setRevoked(0);

		$mapper = $this->createMock(TerminalDeviceMapper::class);
		$mapper->method('findActiveByTokenHash')->willReturn($device);
		$mapper->expects($this->never())->method('update');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(
			new \DateTime('@' . (500 + TerminalDeviceService::MAX_IDLE_SECONDS + 5))
		);

		$svc = $this->service($mapper, $time);
		self::assertNull($svc->resolveToken('Bearer ' . $plain));
	}

	public function testMaxIdleConstantIsNinetyDays(): void
	{
		self::assertSame(90 * 24 * 3600, TerminalDeviceService::MAX_IDLE_SECONDS);
	}
}
