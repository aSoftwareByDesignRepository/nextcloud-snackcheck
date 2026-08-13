<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

final class AccessControlServiceTest extends TestCase
{
	public function testKitchenManagerResolvedFromActiveSites(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$sites = $this->createMock(SiteService::class);

		$groups->method('isAdmin')->willReturn(false);
		$settings->method('getAppAdmins')->willReturn([]);

		$site = new Site();
		$site->setManagersJson(json_encode(['alice'], JSON_THROW_ON_ERROR));
		$sites->method('listActive')->willReturn([$site]);
		$sites->method('managerUids')->willReturn(['alice']);

		$acl = new AccessControlService($settings, $groups, $sites);
		self::assertTrue($acl->isKitchenManager('alice'));
		self::assertFalse($acl->isKitchenManager('bob'));
	}

	public function testListedSiteManagersOverrideWhenProvided(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$sites = $this->createMock(SiteService::class);
		$groups->method('isAdmin')->willReturn(false);
		$settings->method('getAppAdmins')->willReturn([]);
		$sites->expects(self::never())->method('listActive');

		$acl = new AccessControlService($settings, $groups, $sites);
		self::assertTrue($acl->isKitchenManager('carol', ['carol']));
		self::assertFalse($acl->isKitchenManager('dave', ['carol']));
	}

	public function testAssertAccessThrowsWhenDenied(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$sites = $this->createMock(SiteService::class);
		$groups->method('isAdmin')->willReturn(false);
		$settings->method('getAppAdmins')->willReturn([]);
		$settings->method('getAccessMode')->willReturn('listed');
		$settings->method('getAccessUsers')->willReturn([]);
		$settings->method('getAccessGroups')->willReturn([]);

		$acl = new AccessControlService($settings, $groups, $sites);
		$this->expectException(DomainException::class);
		$acl->assertAccess('nobody');
	}

	public function testSiteManagerCannotManageForeignSite(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$sites = $this->createMock(SiteService::class);
		$groups->method('isAdmin')->willReturn(false);
		$settings->method('getAppAdmins')->willReturn([]);
		$settings->method('isMultiSiteEnabled')->willReturn(true);

		$berlin = new Site();
		$berlin->setId(1);
		$berlin->setManagersJson(json_encode(['alice'], JSON_THROW_ON_ERROR));
		$munich = new Site();
		$munich->setId(2);
		$munich->setManagersJson(json_encode(['bob'], JSON_THROW_ON_ERROR));

		$sites->method('listActive')->willReturn([$berlin, $munich]);
		$sites->method('managerUids')->willReturnCallback(static function (Site $s): array {
			return $s->getId() === 1 ? ['alice'] : ['bob'];
		});
		$sites->method('get')->willReturnCallback(static function (int $id) use ($berlin, $munich): Site {
			return $id === 1 ? $berlin : $munich;
		});

		$acl = new AccessControlService($settings, $groups, $sites);
		self::assertTrue($acl->canManageSite('alice', 1));
		self::assertFalse($acl->canManageSite('alice', 2));
		$this->expectException(DomainException::class);
		$acl->resolveManagedSiteId('alice', 2);
	}
}
