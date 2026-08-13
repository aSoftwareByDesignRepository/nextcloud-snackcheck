<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<HospAllow> */
class HospAllowMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_hosp_allow', HospAllow::class);
	}

	public function isAllowed(string $userId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/** @return list<string> */
	public function listUserIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')->from($this->getTableName())->orderBy('user_id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(static fn ($r) => (string)$r['user_id'], $rows);
	}

	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	public function replaceAll(array $userIds, string $actor, \DateTimeInterface $now): void
	{
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())->executeStatement();
			foreach ($userIds as $uid) {
				$row = new HospAllow();
				$row->setUserId($uid);
				$row->setCreatedAt(\DateTime::createFromInterface($now));
				$this->insert($row);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

}
