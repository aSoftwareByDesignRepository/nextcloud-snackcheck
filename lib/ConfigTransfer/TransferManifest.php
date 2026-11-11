<?php

declare(strict_types=1);

/**
 * SnackCheck transfer manifest — every appconfig key and every snk_* table
 * classified exactly once (gate: scripts/check-config-transfer-manifest.py).
 */

namespace OCA\SnackCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local (snackcheck has no
			//    platform restriction mirror; refs are existence-checked) ──
			'access_mode' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'access_users_json' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_groups_json' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admins_json' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── org settings ──
			'subsidy_allowance_cents' => ['class' => 'plain', 'section' => 'benefits'],
			'hospitality_enabled' => ['class' => 'plain', 'section' => 'benefits'],
			'hospitality_company_user_id' => ['class' => 'uid-ref', 'section' => 'benefits'],
			'multi_site_enabled' => 'plain',
			'privacy_totals_only' => ['class' => 'plain', 'security' => true, 'section' => 'privacy'],
			'pace_window_days' => 'plain',
			'restock_horizon_days' => 'plain',
			'weekly_topup_email' => 'plain',
			'personal_digest_enabled' => ['class' => 'plain', 'section' => 'digests'],
			'personal_digest_skip_zero' => ['class' => 'plain', 'section' => 'digests'],
			'personal_digest_days_before' => ['class' => 'plain', 'section' => 'digests'],
			// ── secrets — never leave the instance ──
			'unlock_pin_pepper' => ['class' => 'secret', 'section' => 'unlock'],
			// ── internal bookkeeping — never transferred ──
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// snk_locks lock-key literals the gate sees via ::KEY_* — never
			// appconfig keys, declared internal for completeness.
			'open_period' => 'internal',
			'terminal_capacity' => 'internal',
			'catalog_starter:' => 'internal',
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ catalog / master data ══
			'snk_sites' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code'],
				'json_uid_list' => ['managers_json']],
			'snk_catalog_items' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['site_id', 'name'],
				'fk' => ['site_id' => 'snk_sites'], 'depends_on' => ['snk_sites'],
				'uid_ref' => ['stock_updated_by'], 'uid_ref_nullable' => ['stock_updated_by'],
				// catalog image blobs live in instance appdata — pointers are
				// instance-bound, dropped to NULL (env class, NN-10).
				'env_columns' => ['image_name', 'image_mime']],

			// ══ business data ══
			'snk_periods' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['label'],
				'uid_ref' => ['closed_by', 'handed_to_hr_by'],
				'uid_ref_nullable' => ['closed_by', 'handed_to_hr_by']],
			'snk_consumption_logs' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['idempotency_key'],
				'fk' => ['period_id' => 'snk_periods', 'site_id' => 'snk_sites', 'item_id' => 'snk_catalog_items'],
				'depends_on' => ['snk_periods', 'snk_sites', 'snk_catalog_items'],
				'uid_ref' => ['user_id', 'logged_by', 'voided_by'],
				'uid_ref_nullable' => ['logged_by', 'voided_by'],
				// device_id references a paired terminal — pairing never
				// transfers (NN-10), the column is nullable.
				'env_columns' => ['device_id']],
			'snk_hosp_allow' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id'], 'uid_ref' => ['user_id']],

			// ══ internal / log — NEVER transferred ══
			'snk_audit_events' => ['class' => 'log'],
			'snk_locks' => ['class' => 'internal'],
			'snk_license_state' => ['class' => 'internal'],
			'snk_term_devices' => ['class' => 'internal'],
			'snk_unlock_pins' => ['class' => 'internal'],
			'snk_unlock_qrs' => ['class' => 'internal'],
			'snk_unlock_tokens' => ['class' => 'internal'],
		]);
	}
}
