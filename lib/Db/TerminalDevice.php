<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class TerminalDevice extends Entity
{
	protected $label;
	protected $siteId;
	protected $tokenHash;
	protected $registeredAt;
	protected $registeredBy;
	protected $lastSeenAt;
	protected $revoked;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('revoked', 'integer');
		$this->addType('registeredAt', 'datetime');
		$this->addType('lastSeenAt', 'datetime');
	}
}
