<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use OCA\SnackCheck\Controller\ApiController;
use OCA\SnackCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Listed-mode ACL editors must search the full directory (scope=directory).
 * Proxy pickers keep scope=access (canAccessApp filter).
 */
final class SearchUsersDirectoryScopeTest extends TestCase
{
	private function controller(
		IRequest $request,
		AccessControlService $access,
		IUserManager $users,
	): ApiController {
		$session = $this->createMock(IUserSession::class);
		$me = $this->createMock(IUser::class);
		$me->method('getUID')->willReturn('admin');
		$session->method('getUser')->willReturn($me);

		return new ApiController(
			'snackcheck',
			$request,
			$session,
			$access,
			$this->createMock(\OCA\SnackCheck\Service\ConsumptionLogService::class),
			$this->createMock(\OCA\SnackCheck\Service\CatalogService::class),
			$this->createMock(\OCA\SnackCheck\Service\CatalogImageService::class),
			$this->createMock(\OCA\SnackCheck\Service\PeriodService::class),
			$this->createMock(\OCA\SnackCheck\Service\SiteService::class),
			$this->createMock(\OCA\SnackCheck\Service\SettingsService::class),
			$this->createMock(\OCA\SnackCheck\Service\LicenseService::class),
			$this->createMock(\OCA\SnackCheck\Service\TerminalDeviceService::class),
			$this->createMock(\OCA\SnackCheck\Service\LicenseEnforcementService::class),
			$this->createMock(\OCA\SnackCheck\Service\PayrollExportService::class),
			$this->createMock(\OCA\SnackCheck\Service\UnlockService::class),
			$this->createMock(\OCA\SnackCheck\Db\HospAllowMapper::class),
			$this->createMock(\OCA\SnackCheck\Service\PulseService::class),
			$this->createMock(\OCA\SnackCheck\Service\RateLimitService::class),
			$users,
			$this->createMock(\OCA\SnackCheck\Db\ConsumptionLogMapper::class),
			$this->createMock(\OCA\SnackCheck\Service\SubsidyService::class),
			$this->createMock(\OCA\SnackCheck\Service\AdminTotalsService::class),
			$this->createMock(\OCA\SnackCheck\Service\AuditService::class),
			$this->createMock(\OCA\SnackCheck\Service\DigestMailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(\OCA\SnackCheck\Service\BrAggregateService::class),
			$this->createMock(\OCA\SnackCheck\Service\ComplimentaryExportService::class),
			$this->createMock(\OCA\SnackCheck\Service\ShelfQrService::class),
		);
	}

	/** @return list<IUser> */
	private function directoryUsers(): array
	{
		$allowed = $this->createMock(IUser::class);
		$allowed->method('getUID')->willReturn('alice');
		$allowed->method('getDisplayName')->willReturn('Alice');
		$outsider = $this->createMock(IUser::class);
		$outsider->method('getUID')->willReturn('bob-outside');
		$outsider->method('getDisplayName')->willReturn('Bob Outside');
		return [$allowed, $outsider];
	}

	public function testAccessScopeFiltersCanAccessApp(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $k, $default = null) {
			return match ($k) {
				'q' => 'bo',
				'limit' => 20,
				'scope' => 'access',
				default => $default,
			};
		});
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('assertKitchenManager')->with('admin');
		$access->method('canAccessApp')->willReturnCallback(static fn (string $uid) => $uid === 'alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn($this->directoryUsers());

		$res = $this->controller($request, $access, $users)->searchUsers();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$ids = array_column($data['data']['users'] ?? [], 'userId');
		self::assertSame(['alice'], $ids);
	}

	public function testDirectoryScopeIncludesUsersWithoutAppAccess(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $k, $default = null) {
			return match ($k) {
				'q' => 'bo',
				'limit' => 20,
				'scope' => 'directory',
				default => $default,
			};
		});
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('assertKitchenManager')->with('admin');
		$access->expects($this->once())->method('assertAppAdmin')->with('admin');
		$access->expects($this->never())->method('canAccessApp');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn($this->directoryUsers());

		$res = $this->controller($request, $access, $users)->searchUsers();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$ids = array_column($data['data']['users'] ?? [], 'userId');
		self::assertSame(['alice', 'bob-outside'], $ids);
	}

	public function testDirectoryScopeDeniedForNonAppAdmin(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $k, $default = null) {
			return match ($k) {
				'q' => 'bo',
				'limit' => 20,
				'scope' => 'directory',
				default => $default,
			};
		});
		$access = $this->createMock(AccessControlService::class);
		$access->expects($this->once())->method('assertKitchenManager')->with('admin');
		$access->expects($this->once())->method('assertAppAdmin')->with('admin')
			->willThrowException(new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'App admin required', 403));
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('search');

		$res = $this->controller($request, $access, $users)->searchUsers();
		self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}
}
