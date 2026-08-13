<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class HospAllow extends Entity
{
	protected $userId;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
