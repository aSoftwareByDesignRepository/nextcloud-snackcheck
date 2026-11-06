<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
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
		} catch (DbException $e) {
			$this->db->rollBack();
			// snk_hosp_user_uq race on concurrent replaceAll: surface a friendly
			// 409 instead of the raw DB exception (unique_index_write_no_conflict_path).
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new DomainException('conflict', 'Hospitality access list changed concurrently — reload and retry', 409);
			}
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

}
