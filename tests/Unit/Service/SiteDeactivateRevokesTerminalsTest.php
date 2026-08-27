<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\SiteMapper;
use OCA\SnackCheck\Db\TerminalDeviceMapper;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aristoteles P0: deactivating a kitchen must revoke its tablets under capacity lock.
 */
final class SiteDeactivateRevokesTerminalsTest extends TestCase
{
	public function testDeactivateCallsRevokeAllBySite(): void
	{
		$site = new Site();
		$site->setId(7);
		$site->setCode('BER');
		$site->setName('Berlin');
		$site->setActive(1);

		$mapper = $this->createMock(SiteMapper::class);
		$mapper->method('find')->with(7)->willReturn($site);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Site $s): Site {
				self::assertSame(0, (int)$s->getActive());
				return $s;
			}
		);

		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->expects($this->once())->method('revokeAllBySite')->with(7, 'site-deactivate:7')->willReturn(2);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-27T12:00:00+00:00'));

		$svc = new SiteService(
			$mapper,
			$this->createMock(SettingsService::class),
			$this->createMock(CatalogItemMapper::class),
			$this->createMock(TerminalDeviceMapper::class),
			$terminals,
			$time,
			$this->createMock(ILockingProvider::class),
		);
		$svc->update(7, active: false);
	}

	public function testReactivateDoesNotRevoke(): void
	{
		$site = new Site();
		$site->setId(7);
		$site->setCode('BER');
		$site->setActive(0);

		$mapper = $this->createMock(SiteMapper::class);
		$mapper->method('find')->willReturn($site);
		$mapper->method('update')->willReturnCallback(static fn (Site $s) => $s);

		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->expects($this->never())->method('revokeAllBySite');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-27T12:00:00+00:00'));

		$svc = new SiteService(
			$mapper,
			$this->createMock(SettingsService::class),
			$this->createMock(CatalogItemMapper::class),
			$this->createMock(TerminalDeviceMapper::class),
			$terminals,
			$time,
			$this->createMock(ILockingProvider::class),
		);
		$svc->update(7, active: true);
	}

	public function testSourceContracts(): void
	{
		$siteSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/SiteService.php');
		self::assertStringContainsString('revokeAllBySite', $siteSrc);
		$termSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/TerminalDeviceService.php');
		self::assertMatchesRegularExpression(
			'/function revokeAllBySite[\s\S]{0,400}lockGate->lock\(self::CAPACITY_LOCK\)/',
			$termSrc
		);
		$deviceSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertMatchesRegularExpression(
			'/function authenticateDevice[\s\S]{0,800}sites->get\(/',
			$deviceSrc
		);
		self::assertStringContainsString('assertLiveAppAccess', $deviceSrc);
	}
}
