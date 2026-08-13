<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NN-09: at most one open period — UNIQUE(open_guard) where open_guard=1 for open, NULL for closed.
 * MySQL/PostgreSQL both allow multiple NULLs in a UNIQUE index.
 */
class Version1004Date20260810193000 extends SimpleMigrationStep
{
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('snk_periods')) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')
			->from('snk_periods')
			->where($qb->expr()->eq('state', $qb->createNamedParameter('open')))
			->orderBy('id', 'ASC');
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
		$output->warning('NN-09 repair: keeping open period id=' . $keep . '; closing duplicates');
		foreach ($ids as $id) {
			$upd = $db->getQueryBuilder();
			$upd->update('snk_periods')
				->set('state', $upd->createNamedParameter('closed'))
				->set('closed_by', $upd->createNamedParameter('system-nn09'))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($id)))
				->executeStatement();
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('snk_periods')) {
			return null;
		}
		$table = $schema->getTable('snk_periods');
		if (!$table->hasColumn('open_guard')) {
			$table->addColumn('open_guard', 'integer', [
				'notnull' => false,
				'default' => null,
			]);
		}
		if (!$table->hasIndex('snk_periods_open_uq')) {
			$table->addUniqueIndex(['open_guard'], 'snk_periods_open_uq');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('snk_periods')) {
			return;
		}
		$nullAll = $db->getQueryBuilder();
		$nullAll->update('snk_periods')
			->set('open_guard', $nullAll->createNamedParameter(null))
			->executeStatement();
		$find = $db->getQueryBuilder();
		$find->select('id')
			->from('snk_periods')
			->where($find->expr()->eq('state', $find->createNamedParameter('open')))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$res = $find->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		if ($row === false) {
			return;
		}
		$mark = $db->getQueryBuilder();
		$mark->update('snk_periods')
			->set('open_guard', $mark->createNamedParameter(1))
			->where($mark->expr()->eq('id', $mark->createNamedParameter((int)$row['id'])))
			->executeStatement();
	}
}
