<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\SiteMapper;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\TerminalDeviceMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class SiteService
{
	public const DEFAULT_CODE = 'DEFAULT';
	public const DEFAULT_NAME = 'Default';
	public const MAX_ACTIVE = 20;

	public function __construct(
		private readonly SiteMapper $mapper,
		private readonly SettingsService $settings,
		private readonly CatalogItemMapper $catalog,
		private readonly TerminalDeviceMapper $terminals,
		private readonly TerminalDeviceService $terminalDevices,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $locking,
	) {
	}

	public function ensureDefaultSite(): Site
	{
		$existing = $this->mapper->findByCode(self::DEFAULT_CODE);
		if ($existing !== null) {
			return $existing;
		}
		$now = $this->timeFactory->getDateTime();
		$site = new Site();
		$site->setName(self::DEFAULT_NAME);
		$site->setCode(self::DEFAULT_CODE);
		$site->setActive(1);
		$site->setManagersJson('[]');
		$site->setCreatedAt($now);
		$site->setUpdatedAt($now);
		return $this->mapper->insert($site);
	}

	public function getDefaultSiteId(): int
	{
		return (int)$this->ensureDefaultSite()->getId();
	}

	/** @return list<Site> */
	public function listActive(): array
	{
		$this->ensureDefaultSite();
		return $this->mapper->findAllActive();
	}

	/** @return list<Site> including inactive (admin UI). */
	public function listAll(): array
	{
		$this->ensureDefaultSite();
		return $this->mapper->findAll();
	}

	public function get(int $id): Site
	{
		$site = $this->mapper->find($id);
		if ($site === null || (int)$site->getActive() !== 1) {
			throw new DomainException('not_found', 'Site not found', 404);
		}
		return $site;
	}

	/**
	 * @param list<string> $managerUids
	 */
	public function create(string $name, string $code, array $managerUids = []): Site
	{
		if (!$this->settings->isMultiSiteEnabled()) {
			throw new DomainException('validation_failed', 'Multi-site is off', 422);
		}
		$name = trim($name);
		$code = strtoupper(trim($code));
		if ($name === '' || mb_strlen($name) > 80 || $code === '' || mb_strlen($code) > 40) {
			throw new DomainException('validation_failed', 'Invalid site', 422);
		}
		$lockKey = 'snackcheck/site_create';
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new DomainException('conflict', 'Site create in progress', 409);
		}
		try {
			if ($this->mapper->countActive() >= self::MAX_ACTIVE) {
				throw new DomainException('validation_failed', 'Max sites reached', 422);
			}
			if ($this->mapper->findByCode($code) !== null) {
				throw new DomainException('validation_failed', 'Code exists', 422);
			}
			$now = $this->timeFactory->getDateTime();
			$site = new Site();
			$site->setName($name);
			$site->setCode($code);
			$site->setActive(1);
			$site->setManagersJson(json_encode(array_values($managerUids), JSON_THROW_ON_ERROR));
			$site->setCreatedAt($now);
			$site->setUpdatedAt($now);
			return $this->mapper->insert($site);
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return list<string> */
	public function managerUids(Site $site): array
	{
		$raw = $site->getManagersJson();
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter($decoded, 'is_string'));
	}

	public function resolveScopeSiteId(?int $requestedSiteId): int
	{
		// Never invent Default when multi-site is ambiguous (MH-28 / AC-OPP-Y12).
		return $this->requireExplicitSiteId($requestedSiteId);
	}

	/**
	 * Require an explicit site when multi-site is ON and more than one active site exists.
	 * Prevents silent Default binding for terminals / scoped writes (Y11 / NN-20).
	 */
	public function requireExplicitSiteId(?int $requestedSiteId): int
	{
		if (!$this->settings->isMultiSiteEnabled()) {
			return $this->getDefaultSiteId();
		}
		if ($requestedSiteId === null || $requestedSiteId <= 0) {
			$active = $this->listActive();
			if (count($active) === 1) {
				return (int)$active[0]->getId();
			}
			throw new DomainException('site_required', 'siteId required when multi-site is enabled', 422);
		}
		return (int)$this->get($requestedSiteId)->getId();
	}

	public function canDisableMultiSite(): bool
	{
		if ($this->mapper->countActive() > 1) {
			return false;
		}
		$defaultId = $this->getDefaultSiteId();
		foreach ($this->mapper->findAll() as $site) {
			$id = (int)$site->getId();
			if ($id === $defaultId) {
				continue;
			}
			if ($this->catalog->countActiveBySite($id) > 0) {
				return false;
			}
			if ($this->terminals->countActiveBySite($id) > 0) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param list<string>|null $managerUids
	 */
	public function update(int $id, ?string $name = null, ?array $managerUids = null, ?bool $active = null): Site
	{
		$site = $this->mapper->find($id);
		if ($site === null) {
			throw new DomainException('not_found', 'Site not found', 404);
		}
		$wasActive = (int)$site->getActive() === 1;
		if ($name !== null) {
			$name = trim($name);
			if ($name === '' || mb_strlen($name) > 80) {
				throw new DomainException('validation_failed', 'Invalid name', 422);
			}
			$site->setName($name);
		}
		if ($managerUids !== null) {
			$site->setManagersJson(json_encode(array_values($managerUids), JSON_THROW_ON_ERROR));
		}
		if ($active !== null) {
			if (!$active && $site->getCode() === self::DEFAULT_CODE) {
				throw new DomainException('validation_failed', 'Cannot deactivate default site', 422);
			}
			$site->setActive($active ? 1 : 0);
		}
		$site->setUpdatedAt($this->timeFactory->getDateTime());
		$updated = $this->mapper->update($site);
		// Kill kitchen tablets when a site goes inactive — seats free under capacity lock.
		if ($active === false && $wasActive) {
			$this->terminalDevices->revokeAllBySite($id, 'site-deactivate:' . $id);
		}
		return $updated;
	}
}
