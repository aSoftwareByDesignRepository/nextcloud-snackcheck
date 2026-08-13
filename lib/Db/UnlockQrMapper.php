<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<UnlockQr> */
class UnlockQrMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_unlock_qrs', UnlockQr::class);
	}

	public function findByUserId(string $userId): ?UnlockQr
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

	public function findByTokenHash(string $hash): ?UnlockQr
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)));
		try { return $this->findEntity($qb); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return null; }
	}

}
