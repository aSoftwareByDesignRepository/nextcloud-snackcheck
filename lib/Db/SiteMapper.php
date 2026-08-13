<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Site> */
class SiteMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_sites', Site::class);
	}

	public function find(int $id): ?Site
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	/** @return list<Site> */
	public function findAllActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Site> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByCode(string $code): ?Site
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	public function countActive(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1)));
		return (int)$qb->executeQuery()->fetchOne();
	}

}
