<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<ConsumptionLog> */
class ConsumptionLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_consumption_logs', ConsumptionLog::class);
	}

	public function find(int $id): ?ConsumptionLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	/**
	 * Lock a log row for void/undo races (concurrent double-void / audit duplication).
	 */
	public function lockRow(int $id): ?ConsumptionLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$sql = $qb->getSQL() . ' FOR UPDATE';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return $this->mapRowToEntity($row);
	}

	public function findByIdempotencyKey(string $key): ?ConsumptionLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('idempotency_key', $qb->createNamedParameter($key)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	/** @return list<ConsumptionLog> */
	public function findForPeriod(int $periodId, bool $includeVoided = false): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId)));
		if (!$includeVoided) {
			$qb->andWhere($qb->expr()->isNull('voided_at'));
		}
		$qb->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ConsumptionLog> */
	public function findForUserPeriod(int $periodId, string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('voided_at'))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return list<ConsumptionLog> */
	public function findSince(\DateTimeInterface $since, ?int $siteId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($since->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->isNull('voided_at'));
		if ($siteId !== null) {
			$qb->andWhere($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)));
		}
		return $this->findEntities($qb);
	}

	public function purgeIdempotencyOlderThan(\DateTimeInterface $cutoff): int
	{
		// Retention policy helper — unique key kept 24h for conflict detection.
		// Soft approach: no hard delete of logs; only used in tests/cron stubs.
		return 0;
	}

	public function countNonVoidedForPeriod(int $periodId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId)))
			->andWhere($qb->expr()->isNull('voided_at'));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['c'] ?? 0);
	}

}
