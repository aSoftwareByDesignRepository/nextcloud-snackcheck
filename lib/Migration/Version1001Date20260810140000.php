<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add updated_by on unlock PIN/QR maps.
 */
class Version1001Date20260810140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (['snk_unlock_pins', 'snk_unlock_qrs'] as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$t = $schema->getTable($name);
			if (!$t->hasColumn('updated_by')) {
				$t->addColumn('updated_by', 'string', ['notnull' => false, 'length' => 64, 'default' => '']);
			}
		}

		return $schema;
	}
}
