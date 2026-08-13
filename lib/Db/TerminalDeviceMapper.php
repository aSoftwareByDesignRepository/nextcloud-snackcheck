<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TerminalDevice>
 */
class TerminalDeviceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_term_devices', TerminalDevice::class);
	}

	public function countActive(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('revoked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function countActiveBySite(int $siteId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('revoked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** @return list<TerminalDevice> */
	public function findAllActiveOrdered(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('revoked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findActiveByTokenHash(string $hash): ?TerminalDevice
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function findActiveById(int $id): ?TerminalDevice
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return list<TerminalDevice> */
	public function findActiveNewestFirst(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('revoked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('registered_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
