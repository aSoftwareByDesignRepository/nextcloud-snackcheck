<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\IDBConnection;

/**
 * Shared DB row locks in snk_locks (FOR UPDATE).
 * Prefer this over node-local file locks for multi-node correctness.
 */
class LockGate
{
	public const KEY_OPEN_PERIOD = 'open_period';
	/** Zeus MF-03: terminal register/trim capacity seat gate (DB-enforced). */
	public const KEY_TERMINAL_CAPACITY = 'terminal_capacity';
	/**
	 * Starter-catalog apply uses dynamic keys `catalog_starter:{siteId}`
	 * (created on demand by {@see lock()}). Documented here for auditors.
	 */
	public const KEY_CATALOG_STARTER_PREFIX = 'catalog_starter:';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function lock(string $key): void
	{
		$key = trim($key);
		if ($key === '' || strlen($key) > 64) {
			throw new \InvalidArgumentException('Invalid lock key');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('lock_key')->from('snk_locks')
			->where($qb->expr()->eq('lock_key', $qb->createNamedParameter($key)));
		$sql = $qb->getSQL() . ' FOR UPDATE';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			try {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('snk_locks')->values([
					'lock_key' => $ins->createNamedParameter($key),
				])->executeStatement();
			} catch (\Throwable) {
				// concurrent insert race ok — re-select below
			}
			$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
			$row = $result->fetch();
			$result->closeCursor();
		}
		if ($row === false) {
			throw new \RuntimeException('SnackCheck lock gate unavailable: ' . $key);
		}
	}
}
