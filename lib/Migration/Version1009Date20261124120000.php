<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Expense credits ("Auslagen"): nullable expense_reason on snk_consumption_logs.
 * personal_credit rows reuse the consumption ledger (AS-03) — the reason lives
 * in its own column so item_name_snap stays a canonical display label.
 */
class Version1009Date20261124120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('snk_consumption_logs')) {
			$table = $schema->getTable('snk_consumption_logs');
			if (!$table->hasColumn('expense_reason')) {
				$table->addColumn('expense_reason', 'string', [
					'notnull' => false,
					'length' => 500,
				]);
			}
		}
		return $schema;
	}
}
