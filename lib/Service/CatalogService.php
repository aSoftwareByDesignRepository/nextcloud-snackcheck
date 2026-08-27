<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\LockGate;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class CatalogService
{
	public const CATEGORIES = ['drink', 'snack', 'alcohol', 'other'];

	/** Diet / allergen cues shown on tiles (not colour-only). */
	public const ALLOWED_TAGS = [
		'vegan',
		'vegetarian',
		'gluten_free',
		'lactose_free',
		'contains_nuts',
		'contains_alcohol',
	];

	/** Starter DE catalog — ≥8 items, ≥2 complimentary. */
	public const STARTER_DE = [
		['name' => 'Kaffee', 'price' => 50, 'category' => 'drink'],
		['name' => 'Espresso', 'price' => 50, 'category' => 'drink'],
		['name' => 'Tee', 'price' => 40, 'category' => 'drink'],
		['name' => 'Mineralwasser (still)', 'price' => 0, 'category' => 'drink'],
		['name' => 'Mineralwasser (sprudel)', 'price' => 0, 'category' => 'drink'],
		['name' => 'Apfelsaftschorle', 'price' => 80, 'category' => 'drink'],
		['name' => 'Cola', 'price' => 100, 'category' => 'drink'],
		['name' => 'Schokoriegel', 'price' => 80, 'category' => 'snack'],
		['name' => 'Nüsse', 'price' => 120, 'category' => 'snack'],
		['name' => 'Obst', 'price' => 0, 'category' => 'snack'],
	];

	public function __construct(
		private readonly CatalogItemMapper $mapper,
		private readonly AuditService $audit,
		private readonly ITimeFactory $timeFactory,
		private readonly IDBConnection $db,
		private readonly LockGate $lockGate,
	) {
	}

	/**
	 * Serialize catalog row mutations (restock RMW, price, deactivate) under FOR UPDATE.
	 * Lock order with ledger create: period → item (createLog); catalog-only paths lock item alone.
	 *
	 * @param callable(CatalogItem): CatalogItem $mutator
	 */
	private function mutateLocked(int $id, callable $mutator): CatalogItem
	{
		$this->db->beginTransaction();
		try {
			$item = $this->getForUpdate($id);
			$item = $mutator($item);
			$this->db->commit();
			return $item;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return list<CatalogItem> */
	public function listActive(int $siteId): array
	{
		return $this->mapper->findActiveBySite($siteId);
	}

	/** @return list<CatalogItem> */
	public function listAll(int $siteId): array
	{
		return $this->mapper->findAllBySite($siteId);
	}

	public function get(int $id): CatalogItem
	{
		$item = $this->mapper->find($id);
		if ($item === null) {
			throw new DomainException('not_found', 'Item not found', 404);
		}
		return $item;
	}

	/**
	 * Lock catalog row for ledger create (period lock must already be held — order: period → item).
	 */
	public function getForUpdate(int $id): CatalogItem
	{
		$item = $this->mapper->lockRow($id);
		if ($item === null) {
			throw new DomainException('not_found', 'Item not found', 404);
		}
		return $item;
	}

	/**
	 * @param list<string>|null $tags
	 */
	public function create(
		int $siteId,
		string $name,
		int $priceCents,
		string $actorUid,
		string $category = 'other',
		?string $description = null,
		?int $parLevel = null,
		?int $onHand = null,
		?array $tags = null,
		int $sortOrder = 0,
	): CatalogItem {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new DomainException('validation_failed', 'Invalid name', 422);
		}
		if ($priceCents < 0 || $priceCents > 1_000_000) {
			throw new DomainException('validation_failed', 'Invalid price', 422);
		}
		if (!in_array($category, self::CATEGORIES, true)) {
			$category = 'other';
		}
		$now = $this->timeFactory->getDateTime();
		$item = new CatalogItem();
		$item->setSiteId($siteId);
		$item->setName($name);
		$item->setDescription($description);
		$item->setPriceCents($priceCents);
		$item->setCurrency('EUR');
		$item->setActive(1);
		$item->setSortOrder($sortOrder);
		$item->setParLevel($parLevel);
		$item->setOnHand($onHand);
		$item->setCategory($category);
		$normalized = $this->normalizeTags($tags);
		$item->setTagsJson($normalized === null ? null : json_encode($normalized, JSON_THROW_ON_ERROR));
		$item->setCreatedAt($now);
		$item->setUpdatedAt($now);
		$item = $this->mapper->insert($item);
		$this->audit->record($actorUid, 'catalog.create', 'catalog_item', (string)$item->getId());
		return $item;
	}

	public function updatePrice(int $id, int $priceCents, string $actorUid): CatalogItem
	{
		if ($priceCents < 0 || $priceCents > 1_000_000) {
			throw new DomainException('validation_failed', 'Invalid price', 422);
		}
		return $this->mutateLocked($id, function (CatalogItem $item) use ($id, $priceCents, $actorUid): CatalogItem {
			$item->setPriceCents($priceCents);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->audit->record($actorUid, 'catalog.price', 'catalog_item', (string)$id, ['price_cents' => $priceCents]);
			return $item;
		});
	}

	/**
	 * @param array{
	 *   name?:string,
	 *   priceCents?:int,
	 *   category?:string,
	 *   description?:?string,
	 *   parLevel?:?int,
	 *   onHand?:?int,
	 *   tags?:list<string>|null,
	 *   sortOrder?:int,
	 *   active?:bool
	 * } $fields
	 */
	public function update(int $id, array $fields, string $actorUid): CatalogItem
	{
		return $this->mutateLocked($id, function (CatalogItem $item) use ($id, $fields, $actorUid): CatalogItem {
			if (array_key_exists('name', $fields)) {
				$name = trim((string)$fields['name']);
				if ($name === '' || mb_strlen($name) > 120) {
					throw new DomainException('validation_failed', 'Invalid name', 422);
				}
				$item->setName($name);
			}
			if (array_key_exists('priceCents', $fields)) {
				$priceCents = (int)$fields['priceCents'];
				if ($priceCents < 0 || $priceCents > 1_000_000) {
					throw new DomainException('validation_failed', 'Invalid price', 422);
				}
				$item->setPriceCents($priceCents);
			}
			if (array_key_exists('category', $fields)) {
				$category = (string)$fields['category'];
				$item->setCategory(in_array($category, self::CATEGORIES, true) ? $category : 'other');
			}
			if (array_key_exists('description', $fields)) {
				$item->setDescription($fields['description'] !== null ? (string)$fields['description'] : null);
			}
			if (array_key_exists('parLevel', $fields)) {
				$item->setParLevel($fields['parLevel'] === null ? null : (int)$fields['parLevel']);
			}
			if (array_key_exists('onHand', $fields)) {
				$onHand = $fields['onHand'];
				if ($onHand !== null && (int)$onHand < 0) {
					throw new DomainException('validation_failed', 'Invalid on_hand', 422);
				}
				$item->setOnHand($onHand === null ? null : (int)$onHand);
				$item->setStockUpdatedAt($this->timeFactory->getDateTime());
				$item->setStockUpdatedBy($actorUid);
			}
			if (array_key_exists('tags', $fields)) {
				$normalized = $this->normalizeTags($fields['tags']);
				$item->setTagsJson($normalized === null ? null : json_encode($normalized, JSON_THROW_ON_ERROR));
			}
			if (array_key_exists('sortOrder', $fields)) {
				$item->setSortOrder((int)$fields['sortOrder']);
			}
			if (array_key_exists('active', $fields)) {
				$item->setActive(!empty($fields['active']) ? 1 : 0);
			}
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->audit->record($actorUid, 'catalog.update', 'catalog_item', (string)$id);
			return $item;
		});
	}

	public function softDelete(int $id, string $actorUid): CatalogItem
	{
		return $this->mutateLocked($id, function (CatalogItem $item) use ($id, $actorUid): CatalogItem {
			$item->setActive(0);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->audit->record($actorUid, 'catalog.deactivate', 'catalog_item', (string)$id);
			return $item;
		});
	}

	/** Restock: on_hand += N (never auto-decrement on log). Row-locked to prevent lost updates. */
	public function restock(int $id, int $addQty, string $actorUid): CatalogItem
	{
		if ($addQty < 1) {
			throw new DomainException('validation_failed', 'Invalid restock qty', 422);
		}
		return $this->mutateLocked($id, function (CatalogItem $item) use ($id, $addQty, $actorUid): CatalogItem {
			$current = $item->getOnHand();
			$base = $current === null ? 0 : (int)$current;
			$item->setOnHand($base + $addQty);
			$item->setStockUpdatedAt($this->timeFactory->getDateTime());
			$item->setStockUpdatedBy($actorUid);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->audit->record($actorUid, 'catalog.restock', 'catalog_item', (string)$id, ['add' => $addQty]);
			return $item;
		});
	}

	public function setOnHand(int $id, int $onHand, string $actorUid): CatalogItem
	{
		if ($onHand < 0) {
			throw new DomainException('validation_failed', 'Invalid on_hand', 422);
		}
		return $this->mutateLocked($id, function (CatalogItem $item) use ($id, $onHand, $actorUid): CatalogItem {
			$item->setOnHand($onHand);
			$item->setStockUpdatedAt($this->timeFactory->getDateTime());
			$item->setStockUpdatedBy($actorUid);
			$item->setUpdatedAt($this->timeFactory->getDateTime());
			$item = $this->mapper->update($item);
			$this->audit->record($actorUid, 'catalog.set_on_hand', 'catalog_item', (string)$id, ['on_hand' => $onHand]);
			return $item;
		});
	}

	/**
	 * Idempotent starter apply when site catalog empty.
	 * Serialized per site via LockGate so concurrent "Load starter" cannot double-insert.
	 *
	 * @return list<CatalogItem>
	 */
	public function applyStarterDe(int $siteId, string $actorUid): array
	{
		$this->db->beginTransaction();
		try {
			// Per-site gate (dynamic key; LockGate inserts missing snk_locks rows).
			$this->lockGate->lock('catalog_starter:' . $siteId);
			// Any historical row (incl. soft-deactivated) blocks re-seed — avoids duplicate names.
			if ($this->mapper->countBySite($siteId) > 0) {
				$existing = $this->mapper->findActiveBySite($siteId);
				$this->db->commit();
				return $existing;
			}
			$created = [];
			$sort = 0;
			foreach (self::STARTER_DE as $row) {
				$created[] = $this->create(
					$siteId,
					$row['name'],
					(int)$row['price'],
					$actorUid,
					(string)$row['category'],
					null,
					12, // par — enables pulse Top-up after starter (AC-8 / pack F)
					20, // on hand above par so shelves start healthy
					null,
					$sort++,
				);
			}
			$this->db->commit();
			return $created;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Clone name/price/category/tags to another site (AC-OPP-Y10). Stock starts null.
	 */
	public function copyToSite(int $itemId, int $targetSiteId, string $actorUid): CatalogItem
	{
		$src = $this->get($itemId);
		if ((int)$src->getSiteId() === $targetSiteId) {
			throw new DomainException('validation_failed', 'Target site must differ', 422);
		}
		$tags = null;
		$raw = $src->getTagsJson();
		if (is_string($raw) && $raw !== '') {
			try {
				$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
				if (is_array($decoded)) {
					$tags = array_values(array_filter($decoded, 'is_string'));
				}
			} catch (\JsonException) {
				$tags = null;
			}
		}
		$copy = $this->create(
			$targetSiteId,
			(string)$src->getName(),
			(int)$src->getPriceCents(),
			$actorUid,
			(string)($src->getCategory() ?? 'other'),
			$src->getDescription(),
			null,
			null,
			$tags,
			(int)($src->getSortOrder() ?? 0),
		);
		$this->audit->record($actorUid, 'catalog.copy', 'catalog_item', (string)$copy->getId(), [
			'source_id' => $itemId,
			'target_site_id' => $targetSiteId,
		]);
		return $copy;
	}

	/**
	 * @param list<string>|null $tags
	 * @return list<string>|null
	 */
	public function normalizeTags(?array $tags): ?array
	{
		if ($tags === null) {
			return null;
		}
		$out = [];
		foreach ($tags as $tag) {
			if (!is_string($tag)) {
				continue;
			}
			$t = strtolower(trim($tag));
			if ($t === '' || !in_array($t, self::ALLOWED_TAGS, true)) {
				continue;
			}
			if (!in_array($t, $out, true)) {
				$out[] = $t;
			}
		}
		return $out;
	}
}
