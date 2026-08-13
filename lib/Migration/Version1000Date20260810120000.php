<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial SnackCheck schema (snk_* ≤27 chars).
 */
class Version1000Date20260810120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('snk_sites')) {
			$t = $schema->createTable('snk_sites');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 80]);
			$t->addColumn('code', 'string', ['notnull' => true, 'length' => 40]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('managers_json', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'snk_sites_pk');
			$t->addUniqueIndex(['code'], 'snk_sites_code_uq');
		}

		if (!$schema->hasTable('snk_catalog_items')) {
			$t = $schema->createTable('snk_catalog_items');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 120]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('price_cents', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3, 'default' => 'EUR']);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('category', 'string', ['notnull' => false, 'length' => 32]);
			$t->addColumn('tags_json', 'text', ['notnull' => false]);
			$t->addColumn('par_level', 'integer', ['notnull' => false]);
			$t->addColumn('on_hand', 'integer', ['notnull' => false]);
			$t->addColumn('stock_updated_at', 'datetime', ['notnull' => false]);
			$t->addColumn('stock_updated_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'snk_cat_pk');
			$t->addIndex(['site_id', 'active'], 'snk_cat_site_act_idx');
		}

		if (!$schema->hasTable('snk_periods')) {
			$t = $schema->createTable('snk_periods');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('starts_on', 'date', ['notnull' => true]);
			$t->addColumn('ends_on', 'date', ['notnull' => true]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('closed_at', 'datetime', ['notnull' => false]);
			$t->addColumn('closed_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('reopen_reason', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('handed_to_hr_at', 'datetime', ['notnull' => false]);
			$t->addColumn('handed_to_hr_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'snk_periods_pk');
			$t->addIndex(['state'], 'snk_periods_state_idx');
			$t->addUniqueIndex(['label'], 'snk_periods_label_uq');
		}

		if (!$schema->hasTable('snk_consumption_logs')) {
			$t = $schema->createTable('snk_consumption_logs');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('period_id', 'bigint', ['notnull' => true]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('user_display_snap', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('item_id', 'bigint', ['notnull' => false]);
			$t->addColumn('item_name_snap', 'string', ['notnull' => true, 'length' => 120]);
			$t->addColumn('qty', 'integer', ['notnull' => true]);
			$t->addColumn('unit_price_cents', 'integer', ['notnull' => true]);
			$t->addColumn('line_total_cents', 'integer', ['notnull' => true]);
			$t->addColumn('billing_bucket', 'string', ['notnull' => true, 'length' => 32, 'default' => 'personal']);
			$t->addColumn('source', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('device_id', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('logged_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('proxy_reason', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('hosp_reason', 'string', ['notnull' => false, 'length' => 500]);
			$t->addColumn('idempotency_key', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('voided_at', 'datetime', ['notnull' => false]);
			$t->addColumn('voided_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('void_reason', 'string', ['notnull' => false, 'length' => 500]);
			$t->setPrimaryKey(['id'], 'snk_logs_pk');
			$t->addUniqueIndex(['idempotency_key'], 'snk_logs_idem_uq');
			$t->addIndex(['period_id', 'user_id'], 'snk_logs_per_user_idx');
			$t->addIndex(['period_id', 'site_id'], 'snk_logs_per_site_idx');
			$t->addIndex(['period_id', 'billing_bucket'], 'snk_logs_per_bill_idx');
			$t->addIndex(['created_at'], 'snk_logs_created_idx');
		}

		if (!$schema->hasTable('snk_audit_events')) {
			$t = $schema->createTable('snk_audit_events');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('actor_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('action', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('entity_type', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('entity_id', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('payload_json', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'snk_audit_pk');
			$t->addIndex(['created_at'], 'snk_audit_created_idx');
		}

		if (!$schema->hasTable('snk_license_state')) {
			$t = $schema->createTable('snk_license_state');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('valid_until', 'date', ['notnull' => true]);
			$t->addColumn('mobile_seats', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('terminal_devices', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('bundle', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('key_applied_at', 'datetime', ['notnull' => true]);
			$t->addColumn('payload_b64', 'text', ['notnull' => true]);
			$t->addColumn('signature_b64', 'text', ['notnull' => true]);
			$t->addColumn('license_fingerprint', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('bound_instance_id', 'string', ['notnull' => false, 'length' => 128]);
			$t->setPrimaryKey(['id'], 'snk_lic_pk');
		}

		if (!$schema->hasTable('snk_term_devices')) {
			$t = $schema->createTable('snk_term_devices');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('site_id', 'bigint', ['notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('registered_at', 'datetime', ['notnull' => true]);
			$t->addColumn('registered_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('last_seen_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'snk_term_pk');
			$t->addUniqueIndex(['token_hash'], 'snk_term_hash_uq');
			$t->addIndex(['site_id', 'revoked'], 'snk_term_site_idx');
		}

		if (!$schema->hasTable('snk_unlock_pins')) {
			$t = $schema->createTable('snk_unlock_pins');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('pin_hash', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('fail_count', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('locked_until', 'datetime', ['notnull' => false]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_by', 'string', ['notnull' => false, 'length' => 64, 'default' => '']);
			$t->setPrimaryKey(['id'], 'snk_pins_pk');
			$t->addUniqueIndex(['user_id'], 'snk_pins_user_uq');
			$t->addUniqueIndex(['pin_hash'], 'snk_pins_hash_uq');
		}

		if (!$schema->hasTable('snk_unlock_qrs')) {
			$t = $schema->createTable('snk_unlock_qrs');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_by', 'string', ['notnull' => false, 'length' => 64, 'default' => '']);
			$t->setPrimaryKey(['id'], 'snk_qrs_pk');
			$t->addUniqueIndex(['token_hash'], 'snk_qrs_hash_uq');
			$t->addUniqueIndex(['user_id'], 'snk_qrs_user_uq');
		}

		if (!$schema->hasTable('snk_hosp_allow')) {
			$t = $schema->createTable('snk_hosp_allow');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'snk_hosp_pk');
			$t->addUniqueIndex(['user_id'], 'snk_hosp_user_uq');
		}

		if (!$schema->hasTable('snk_unlock_tokens')) {
			$t = $schema->createTable('snk_unlock_tokens');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('device_id', 'bigint', ['notnull' => true]);
			$t->addColumn('is_kitchen_admin', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('is_hosp_allowed', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'snk_utok_pk');
			$t->addUniqueIndex(['token_hash'], 'snk_utok_hash_uq');
			$t->addIndex(['expires_at'], 'snk_utok_exp_idx');
		}

		return $schema;
	}
}
