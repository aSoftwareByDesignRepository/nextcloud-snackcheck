<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class UnlockPin extends Entity
{
	protected $userId;
	protected $pinHash;
	protected $failCount;
	protected $lockedUntil;
	protected $updatedAt;
	protected $updatedBy;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('failCount', 'integer');
		$this->addType('lockedUntil', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
