<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Singleton lock row for exclusive open-period creation (NN-09).
 */
class Version1002Date20260810160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('snk_locks')) {
			$t = $schema->createTable('snk_locks');
			$t->addColumn('lock_key', 'string', ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['lock_key'], 'snk_locks_pk');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('snk_locks')
			->where($qb->expr()->eq('lock_key', $qb->createNamedParameter('open_period')));
		$count = (int)$qb->executeQuery()->fetchOne();
		if ($count === 0) {
			$ins = $db->getQueryBuilder();
			$ins->insert('snk_locks')->values([
				'lock_key' => $ins->createNamedParameter('open_period'),
			])->executeStatement();
		}
	}
}
