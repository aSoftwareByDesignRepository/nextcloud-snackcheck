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

final class AccessControlSiteRequiredTest extends TestCase
{
	public function testMultiSiteRequiresExplicitSiteWhenMultipleVisible(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$sites = $this->createMock(SiteService::class);
		$groups->method('isAdmin')->willReturn(true);
		$settings->method('getAppAdmins')->willReturn(['admin']);
		$settings->method('isMultiSiteEnabled')->willReturn(true);

		$a = new Site();
		$a->setId(1);
		$b = new Site();
		$b->setId(2);
		$sites->method('listActive')->willReturn([$a, $b]);

		$acl = new AccessControlService($settings, $groups, $sites);
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('siteId required');
		$acl->resolveManagedSiteId('admin', null);
	}

	public function testSingleVisibleSiteStillResolves(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$groups = $this->createMock(IGroupManager::class);
		$sites = $this->createMock(SiteService::class);
		$groups->method('isAdmin')->willReturn(true);
		$settings->method('getAppAdmins')->willReturn(['admin']);
		$settings->method('isMultiSiteEnabled')->willReturn(true);

		$a = new Site();
		$a->setId(7);
		$sites->method('listActive')->willReturn([$a]);

		$acl = new AccessControlService($settings, $groups, $sites);
		self::assertSame(7, $acl->resolveManagedSiteId('admin', null));
	}
}
