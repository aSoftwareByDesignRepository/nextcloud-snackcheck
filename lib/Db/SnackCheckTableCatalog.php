<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

final class SnackCheckTableCatalog
{
	/** @var list<string> */
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
}
