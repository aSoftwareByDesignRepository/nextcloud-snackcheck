<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after snackcheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=snackcheck
 */
namespace OCA\SnackCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
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
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [
		'snk_unlock_tokens',
	];
}
