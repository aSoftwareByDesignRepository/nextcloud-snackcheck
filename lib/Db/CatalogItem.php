<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\Entity;

class CatalogItem extends Entity
{
	protected $siteId;
	protected $name;
	protected $description;
	protected $priceCents;
	protected $currency;
	protected $active;
	protected $sortOrder;
	protected $parLevel;
	protected $onHand;
	protected $stockUpdatedAt;
	protected $stockUpdatedBy;
	protected $category;
	protected $tagsJson;
	protected $imageName;
	protected $imageMime;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('siteId', 'integer');
		$this->addType('priceCents', 'integer');
		$this->addType('active', 'integer');
		$this->addType('sortOrder', 'integer');
		$this->addType('parLevel', 'integer');
		$this->addType('onHand', 'integer');
		$this->addType('stockUpdatedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
