<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class Site extends Entity
{
	protected $name;
	protected $code;
	protected $active;
	/** Maps to managers_json */
	protected $managersJson;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('active', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
