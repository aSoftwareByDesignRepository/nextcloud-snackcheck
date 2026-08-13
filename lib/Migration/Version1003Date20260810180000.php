<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * AC-M19: UNIQUE(pin_hash) so concurrent PIN sets cannot create attribution collisions.
 */
class Version1003Date20260810180000 extends SimpleMigrationStep
{
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('snk_unlock_pins')) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id', 'pin_hash')
			->from('snk_unlock_pins')
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$seen = [];
		$deleteIds = [];
		while ($row = $result->fetch()) {
			$hash = (string)$row['pin_hash'];
			$id = (int)$row['id'];
			if (isset($seen[$hash])) {
				$deleteIds[] = $id;
			} else {
				$seen[$hash] = $id;
			}
		}
		$result->closeCursor();
		foreach ($deleteIds as $id) {
			$del = $db->getQueryBuilder();
			$del->delete('snk_unlock_pins')
				->where($del->expr()->eq('id', $del->createNamedParameter($id)))
				->executeStatement();
			$output->info('Removed duplicate unlock PIN row id=' . $id);
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('snk_unlock_pins')) {
			return null;
		}
		$table = $schema->getTable('snk_unlock_pins');
		if (!$table->hasColumn('updated_by')) {
			$table->addColumn('updated_by', 'string', ['notnull' => false, 'length' => 64, 'default' => '']);
		}
		if (!$table->hasIndex('snk_pins_hash_uq')) {
			$table->addUniqueIndex(['pin_hash'], 'snk_pins_hash_uq');
		}
		if ($schema->hasTable('snk_unlock_qrs')) {
			$qrs = $schema->getTable('snk_unlock_qrs');
			if (!$qrs->hasColumn('updated_by')) {
				$qrs->addColumn('updated_by', 'string', ['notnull' => false, 'length' => 64, 'default' => '']);
			}
		}
		return $schema;
	}
}
