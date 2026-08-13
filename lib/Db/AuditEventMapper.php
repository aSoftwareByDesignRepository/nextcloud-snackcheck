<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<AuditEvent> */
class AuditEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_audit_events', AuditEvent::class);
	}

	/** @return list<AuditEvent> */
	public function findRecent(int $limit = 100): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('created_at', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

}
