<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zeus Absolute No-Go hygiene: drop dead snk_unlock_tokens.
 *
 * Unlock sessions live only in ICache createDistributed('snackcheck_unlock').
 * The v1000 table had zero writers and invited a dual-SoT footgun for future maintainers.
 * UninstallDropTables retains the name (DROP IF EXISTS) for legacy installs.
 */
class Version1007Date20260827141500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('snk_unlock_tokens')) {
			$schema->dropTable('snk_unlock_tokens');
		}
		return $schema;
	}
}
