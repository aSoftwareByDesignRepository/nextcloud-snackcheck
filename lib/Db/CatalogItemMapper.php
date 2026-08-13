<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<CatalogItem> */
class CatalogItemMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_catalog_items', CatalogItem::class);
	}

	public function find(int $id): ?CatalogItem
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	/** Aristoteles: hold catalog row under FOR UPDATE while creating a ledger line. */
	public function lockRow(int $id): ?CatalogItem
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

	/** @return list<CatalogItem> */
	public function findActiveBySite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)))
			->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<CatalogItem> */
	public function findAllBySite(int $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	public function countActiveBySite(int $siteId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1)));
		return (int)$qb->executeQuery()->fetchOne();
	}

}
