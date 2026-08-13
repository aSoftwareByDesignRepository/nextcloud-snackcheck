<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class AuditEvent extends Entity
{
	protected $createdAt;
	protected $actorUid = '';
	protected $action = '';
	protected $entityType = '';
	protected $entityId;
	protected $payloadJson;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
