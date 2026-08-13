<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zeus MF: snk_license_state must be a DB-enforced singleton.
 * Dual rows + findCurrent→null falsely 402'd every tablet.
 */
class Version1005Date20260810210000 extends SimpleMigrationStep
{
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('snk_license_state')) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')
			->from('snk_license_state')
			->orderBy('id', 'DESC');
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		if (count($ids) <= 1) {
			return;
		}
		$keep = array_shift($ids);
		$output->warning('License singleton repair: keeping id=' . $keep . '; deleting ' . count($ids) . ' duplicate(s)');
		foreach ($ids as $id) {
			$del = $db->getQueryBuilder();
			$del->delete('snk_license_state')
				->where($del->expr()->eq('id', $del->createNamedParameter($id)))
				->executeStatement();
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('snk_license_state')) {
			return null;
		}
		$table = $schema->getTable('snk_license_state');
		if (!$table->hasColumn('singleton_guard')) {
			$table->addColumn('singleton_guard', 'integer', [
				'notnull' => true,
				'default' => 1,
			]);
		}
		if (!$table->hasIndex('snk_lic_single_uq')) {
			$table->addUniqueIndex(['singleton_guard'], 'snk_lic_single_uq');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('snk_license_state')) {
			return;
		}
		$mark = $db->getQueryBuilder();
		$mark->update('snk_license_state')
			->set('singleton_guard', $mark->createNamedParameter(1))
			->executeStatement();
	}
}
