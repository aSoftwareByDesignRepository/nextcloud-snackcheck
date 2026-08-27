<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\SiteMapper;
use OCA\SnackCheck\Db\TerminalDeviceMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class SiteMultiSiteGuardsTest extends TestCase
{
	private function service(
		SiteMapper $mapper,
		SettingsService $settings,
		?CatalogItemMapper $catalog = null,
		?TerminalDeviceMapper $terminals = null,
		?\OCA\SnackCheck\Service\TerminalDeviceService $terminalDevices = null,
	): SiteService {
		return new SiteService(
			$mapper,
			$settings,
			$catalog ?? $this->createMock(CatalogItemMapper::class),
			$terminals ?? $this->createMock(TerminalDeviceMapper::class),
			$terminalDevices ?? $this->createMock(\OCA\SnackCheck\Service\TerminalDeviceService::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
	}

	public function testRequireExplicitSiteIdWhenMultiSiteAndAmbiguous(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isMultiSiteEnabled')->willReturn(true);
		$a = new Site();
		$a->setId(1);
		$a->setCode('DEFAULT');
		$b = new Site();
		$b->setId(2);
		$b->setCode('BER');
		$mapper = $this->createMock(SiteMapper::class);
		$mapper->method('findAllActive')->willReturn([$a, $b]);
		$mapper->method('findByCode')->willReturn($a);

		$svc = $this->service($mapper, $settings);
		$this->expectException(DomainException::class);
		try {
			$svc->requireExplicitSiteId(null);
		} catch (DomainException $e) {
			self::assertSame('site_required', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
			throw $e;
		}
	}

	public function testResolveScopeSiteIdNeverFallsBackToDefaultWhenAmbiguous(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isMultiSiteEnabled')->willReturn(true);
		$a = new Site();
		$a->setId(1);
		$a->setCode('DEFAULT');
		$b = new Site();
		$b->setId(2);
		$b->setCode('BER');
		$mapper = $this->createMock(SiteMapper::class);
		$mapper->method('findAllActive')->willReturn([$a, $b]);
		$mapper->method('findByCode')->willReturn($a);

		$svc = $this->service($mapper, $settings);
		$this->expectException(DomainException::class);
		try {
			$svc->resolveScopeSiteId(null);
		} catch (DomainException $e) {
			self::assertSame('site_required', $e->errorCode);
			throw $e;
		}
	}

	public function testCanDisableBlockedWhenNonDefaultHasCatalog(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$default = new Site();
		$default->setId(1);
		$default->setCode('DEFAULT');
		$default->setActive(1);
		$berlin = new Site();
		$berlin->setId(2);
		$berlin->setCode('BER');
		$berlin->setActive(0);

		$mapper = $this->createMock(SiteMapper::class);
		$mapper->method('countActive')->willReturn(1);
		$mapper->method('findByCode')->willReturn($default);
		$mapper->method('findAll')->willReturn([$default, $berlin]);
		$mapper->method('findAllActive')->willReturn([$default]);

		$catalog = $this->createMock(CatalogItemMapper::class);
		$catalog->method('countActiveBySite')->willReturnCallback(static fn (int $id) => $id === 2 ? 3 : 0);
		$terminals = $this->createMock(TerminalDeviceMapper::class);
		$terminals->method('countActiveBySite')->willReturn(0);

		$svc = $this->service($mapper, $settings, $catalog, $terminals);
		self::assertFalse($svc->canDisableMultiSite());
	}

	public function testCanDisableWhenOnlyDefaultClean(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$default = new Site();
		$default->setId(1);
		$default->setCode('DEFAULT');

		$mapper = $this->createMock(SiteMapper::class);
		$mapper->method('countActive')->willReturn(1);
		$mapper->method('findByCode')->willReturn($default);
		$mapper->method('findAll')->willReturn([$default]);
		$mapper->method('findAllActive')->willReturn([$default]);

		$catalog = $this->createMock(CatalogItemMapper::class);
		$catalog->method('countActiveBySite')->willReturn(0);
		$terminals = $this->createMock(TerminalDeviceMapper::class);
		$terminals->method('countActiveBySite')->willReturn(0);

		$svc = $this->service($mapper, $settings, $catalog, $terminals);
		self::assertTrue($svc->canDisableMultiSite());
	}
}
