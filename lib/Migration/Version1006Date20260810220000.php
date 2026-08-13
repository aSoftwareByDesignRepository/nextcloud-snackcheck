<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zeus MF-03: seed snk_locks.terminal_capacity for DB-enforced seat races
 * (ILockingProvider is node-local and insufficient on multi-node).
 */
class Version1006Date20260810220000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var \OCP\IDBConnection $db */
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('snk_locks')) {
			return;
		}
		$key = \OCA\SnackCheck\Db\LockGate::KEY_TERMINAL_CAPACITY;
		$qb = $db->getQueryBuilder();
		$qb->select('lock_key')
			->from('snk_locks')
			->where($qb->expr()->eq('lock_key', $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$exists = $result->fetch() !== false;
		$result->closeCursor();
		if ($exists) {
			return;
		}
		try {
			$ins = $db->getQueryBuilder();
			$ins->insert('snk_locks')->values([
				'lock_key' => $ins->createNamedParameter($key),
			])->executeStatement();
			$output->info('Seeded snk_locks.' . $key);
		} catch (\Throwable $e) {
			$output->warning('Could not seed terminal_capacity lock: ' . $e->getMessage());
		}
	}
}
