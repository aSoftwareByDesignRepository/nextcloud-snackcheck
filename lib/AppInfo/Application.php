<?php

declare(strict_types=1);

namespace OCA\SnackCheck\AppInfo;

use OCA\SnackCheck\Command\UpgradeBackupCommand;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Repair\BackupBeforeUpdate;
use OCA\SnackCheck\Repair\EnsureSnackCheckSchema;
use OCA\SnackCheck\Repair\UninstallDropTables;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Support\NavGate;
use OCA\SnackCheck\Service\CatalogImageService;
use OCA\SnackCheck\Service\UpgradeBackupService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'snackcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(CatalogImageService::class, static function ($c): CatalogImageService {
			return new CatalogImageService(
				$c->get(IDBConnection::class),
				$c->get(IAppDataFactory::class)->get(Application::APP_ID),
				$c->get(CatalogItemMapper::class),
				$c->get(AuditService::class),
				$c->get(ITimeFactory::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(EnsureSnackCheckSchema::class, function ($c): EnsureSnackCheckSchema {
			return new EnsureSnackCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});

		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(LoggerInterface::class),
				$c->get(\OCA\SnackCheck\Service\TerminalDeviceService::class),
				$c->get(\OCA\SnackCheck\Service\LicenseService::class),
			);
		});
		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate($c->get(UpgradeBackupService::class));
		});
		// ── Config & data transfer (test → production) ──────────────────
		$context->registerService(\OCA\SnackCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\SnackCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\SnackCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\SnackCheck\ConfigTransfer\TransferHooks {
			return new \OCA\SnackCheck\ConfigTransfer\TransferHooks(
				$c->get(\OCA\SnackCheck\Service\SettingsService::class),
				$c->get(\OCA\SnackCheck\Service\AccessControlService::class),
				$c->get(UpgradeBackupService::class),
				$c->get(\OCA\SnackCheck\Service\AuditService::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
			);
		});
		$context->registerService(\OCA\SnackCheck\ConfigTransfer\TransferPendingStore::class, function ($c): \OCA\SnackCheck\ConfigTransfer\TransferPendingStore {
			return new \OCA\SnackCheck\ConfigTransfer\TransferPendingStore(
				$c->get(IRootFolder::class),
				$c->get(IConfig::class),
				'snackcheck',
			);
		});
		$context->registerService(\OCA\SnackCheck\ConfigTransfer\TransferReceiptStore::class, function ($c): \OCA\SnackCheck\ConfigTransfer\TransferReceiptStore {
			return new \OCA\SnackCheck\ConfigTransfer\TransferReceiptStore(
				$c->get(IConfig::class),
				'snackcheck',
			);
		});
		$context->registerService(\OCA\SnackCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\SnackCheck\ConfigTransfer\TransferService {
			return new \OCA\SnackCheck\ConfigTransfer\TransferService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(LoggerInterface::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferReceiptStore::class),
				'snackcheck',
				$c->get(\OCP\IAppConfig::class),
			);
		});
		$context->registerService(\OCA\SnackCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\SnackCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\SnackCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferService::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\SnackCheck\Controller\TransferApiController::class, function ($c): \OCA\SnackCheck\Controller\TransferApiController {
			return new \OCA\SnackCheck\Controller\TransferApiController(
				'snackcheck',
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\SnackCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\OCP\Security\Bruteforce\IThrottler::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserSession::class),
			);
		});
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		// Menu entry only for users with app access — in listed mode the
		// door denies outsiders, so the entry must not advertise it.
		NavGate::register(
			$app,
			'snackcheck.page.index',
			'app.svg',
			'SnackCheck',
			10,
			static fn(string $uid): bool => $app->get(AccessControlService::class)->canAccessApp($uid),
		);
	}
}
