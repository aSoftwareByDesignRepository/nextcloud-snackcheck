<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Optional catalog item pictures (AppData-backed; columns hold filename + mime).
 */
class Version1008Date20260827170000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('snk_catalog_items')) {
			$table = $schema->getTable('snk_catalog_items');
			if (!$table->hasColumn('image_name')) {
				$table->addColumn('image_name', 'string', [
					'notnull' => false,
					'length' => 128,
				]);
			}
			if (!$table->hasColumn('image_mime')) {
				$table->addColumn('image_mime', 'string', [
					'notnull' => false,
					'length' => 64,
				]);
			}
		}
		return $schema;
	}
}
