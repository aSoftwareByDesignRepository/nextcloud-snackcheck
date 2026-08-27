<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

final class SnackCheckTableCatalog
{
	/**
	 * Every table this app has ever created (uninstall DROP IF EXISTS target list).
	 * Includes legacy tables intentionally dropped by later migrations.
	 *
	 * @var list<string>
	 */
	public const TABLES = [
		'snk_audit_events',
		'snk_catalog_items',
		'snk_consumption_logs',
		'snk_hosp_allow',
		'snk_license_state',
		'snk_locks',
		'snk_periods',
		'snk_sites',
		'snk_term_devices',
		'snk_unlock_pins',
		'snk_unlock_qrs',
		'snk_unlock_tokens',
	];

	/**
	 * Tables that must exist after current migrations (excludes intentionally dropped legacy).
	 *
	 * @var list<string>
	 */
	public const DROPPED_LEGACY_TABLES = [
		'snk_unlock_tokens',
	];

	/**
	 * @return list<string>
	 */
	public static function requiredTables(): array
	{
		$dropped = array_fill_keys(self::DROPPED_LEGACY_TABLES, true);
		$out = [];
		foreach (self::TABLES as $table) {
			if (!isset($dropped[$table])) {
				$out[] = $table;
			}
		}
		return $out;
	}
}
