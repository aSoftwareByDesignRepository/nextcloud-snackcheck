<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class ConsumptionLog extends Entity
{
	protected $periodId;
	protected $siteId;
	protected $userId;
	protected $userDisplaySnap;
	protected $itemId;
	protected $itemNameSnap;
	protected $qty;
	protected $unitPriceCents;
	protected $lineTotalCents;
	protected $billingBucket;
	protected $source;
	protected $deviceId;
	protected $loggedBy;
	protected $proxyReason;
	protected $hospReason;
	protected $idempotencyKey;
	protected $createdAt;
	protected $voidedAt;
	protected $voidedBy;
	protected $voidReason;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('periodId', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('itemId', 'integer');
		$this->addType('qty', 'integer');
		$this->addType('unitPriceCents', 'integer');
		$this->addType('lineTotalCents', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('voidedAt', 'datetime');
	}
}
