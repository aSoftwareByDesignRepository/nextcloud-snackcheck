<?php

declare(strict_types=1);

namespace OCA\SnackCheck\AppInfo;

use OCA\SnackCheck\Command\UpgradeBackupCommand;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Repair\BackupBeforeUpdate;
use OCA\SnackCheck\Repair\EnsureSnackCheckSchema;
use OCA\SnackCheck\Repair\UninstallDropTables;
use OCA\SnackCheck\Service\AuditService;
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
	}

	public function boot(IBootContext $context): void
	{
	}
}
