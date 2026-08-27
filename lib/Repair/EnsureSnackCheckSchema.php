<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Repair;

use OCA\SnackCheck\Db\SnackCheckTableCatalog;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Idempotent schema presence check after install / post-migration.
 */
final class EnsureSnackCheckSchema implements IRepairStep
{
	public const APP_ID = 'snackcheck';

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure snackcheck core tables exist';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(self::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$missing = [];
		foreach (SnackCheckTableCatalog::requiredTables() as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			$output->warning('snackcheck missing tables: ' . implode(', ', $missing) . ' — run migrations');
		} else {
			$output->info('snackcheck schema OK');
		}
	}
}
