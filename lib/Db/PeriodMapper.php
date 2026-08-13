<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Period> */
class PeriodMapper extends QBMapper
{
	public function __construct(
		IDBConnection $db,
		private readonly LockGate $lockGate,
	) {
		parent::__construct($db, 'snk_periods', Period::class);
	}

	public function find(int $id): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	public function findOpen(): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('open')))
			->setMaxResults(1);
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	public function findByLabel(string $label): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('label', $qb->createNamedParameter($label)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	/** @return list<Period> */
	public function findAllOrdered(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('starts_on', 'DESC');
		return $this->findEntities($qb);
	}

	public function lockRow(int $id): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		// FOR UPDATE when supported
		$sql = $qb->getSQL() . ' FOR UPDATE';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return $this->mapRowToEntity($row);
	}

	public function lockOpenPeriodGate(): void
	{
		$this->lockGate->lock(LockGate::KEY_OPEN_PERIOD);
	}

	public function lockOpen(): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('open')));
		$sql = $qb->getSQL() . ' FOR UPDATE';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return $this->mapRowToEntity($row);
	}

	public function findPreviousClosed(int $beforeId): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('closed')))
			->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function findLatestClosed(): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter('closed')))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName());
		return (int)$qb->executeQuery()->fetchOne();
	}
}
