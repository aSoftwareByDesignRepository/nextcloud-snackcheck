<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * NOTE: Do not default required NOT NULL columns to the same value you set on insert —
 * Nextcloud Entity skips dirty-tracking when new value === current property value.
 */
class Period extends Entity
{
	protected $label;
	protected $startsOn;
	protected $endsOn;
	protected $state;
	protected $closedAt;
	protected $closedBy;
	protected $reopenReason;
	protected $handedToHrAt;
	protected $handedToHrBy;
	protected $createdAt;
	/** @var int|null 1 when open — UNIQUE so at most one open period (NN-09) */
	protected $openGuard;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('startsOn', 'date');
		$this->addType('endsOn', 'date');
		$this->addType('closedAt', 'datetime');
		$this->addType('handedToHrAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('openGuard', 'integer');
	}
}
