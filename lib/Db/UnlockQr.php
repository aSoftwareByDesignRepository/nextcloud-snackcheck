<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class UnlockQr extends Entity
{
	protected $userId;
	protected $tokenHash;
	protected $updatedAt;
	protected $updatedBy;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('updatedAt', 'datetime');
	}
}
