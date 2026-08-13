<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCP\IGroupManager;

/**
 * Access door: open vs listed ACL. App admins always pass.
 * Kitchen managers = app admins OR listed on any active site.
 * Web routes are never gated by SNK2 licence.
 */
class AccessControlService
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly IGroupManager $groupManager,
		private readonly SiteService $sites,
	) {
	}

	public function canAccessApp(string $userId): bool
	{
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if ($this->settings->getAccessMode() === 'open') {
			return true;
		}
		if (in_array($userId, $this->settings->getAccessUsers(), true)) {
			return true;
		}
		foreach ($this->settings->getAccessGroups() as $gid) {
			if ($this->groupManager->isInGroup($userId, $gid)) {
				return true;
			}
		}
		return false;
	}

	public function isAppAdmin(string $userId): bool
	{
		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}
		return in_array($userId, $this->settings->getAppAdmins(), true);
	}

	/**
	 * @param list<string>|null $siteManagerUids when null, resolve from all active sites
	 */
	public function isKitchenManager(string $userId, ?array $siteManagerUids = null): bool
	{
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if ($siteManagerUids !== null) {
			return in_array($userId, $siteManagerUids, true);
		}
		foreach ($this->sites->listActive() as $site) {
			if (in_array($userId, $this->sites->managerUids($site), true)) {
				return true;
			}
		}
		return false;
	}

	public function assertAccess(string $userId): void
	{
		if (!$this->canAccessApp($userId)) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'Access denied', 403);
		}
	}

	public function assertAppAdmin(string $userId): void
	{
		if (!$this->isAppAdmin($userId)) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'App admin required', 403);
		}
	}

	public function assertKitchenManager(string $userId): void
	{
		if (!$this->isKitchenManager($userId)) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'Kitchen manager required', 403);
		}
	}

	/**
	 * App admins manage all sites. Site managers only their allowlisted sites (AC-OPP-Y5/Y20).
	 */
	public function canManageSite(string $userId, int $siteId): bool
	{
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if (!$this->isKitchenManager($userId)) {
			return false;
		}
		try {
			$site = $this->sites->get($siteId);
		} catch (\OCA\SnackCheck\Exception\DomainException) {
			return false;
		}
		return in_array($userId, $this->sites->managerUids($site), true);
	}

	public function assertCanManageSite(string $userId, int $siteId): void
	{
		if (!$this->canManageSite($userId, $siteId)) {
			throw new \OCA\SnackCheck\Exception\DomainException('foreign_site', 'Site not allowed for this manager', 403);
		}
	}

	/**
	 * Sites visible in scope selector: all for app admin; managed only for site managers (Y6).
	 *
	 * @return list<\OCA\SnackCheck\Db\Site>
	 */
	public function sitesVisibleTo(string $userId): array
	{
		$all = $this->sites->listActive();
		if ($this->isAppAdmin($userId)) {
			return $all;
		}
		$out = [];
		foreach ($all as $site) {
			if (in_array($userId, $this->sites->managerUids($site), true)) {
				$out[] = $site;
			}
		}
		return $out;
	}

	/**
	 * Resolve kitchen scope for manager APIs. Foreign site_id → 403 (Y20).
	 */
	public function resolveManagedSiteId(string $userId, ?int $requestedSiteId): int
	{
		if (!$this->settings->isMultiSiteEnabled()) {
			return $this->sites->getDefaultSiteId();
		}
		$visible = $this->sitesVisibleTo($userId);
		if ($visible === []) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'No managed site', 403);
		}
		if ($requestedSiteId === null || $requestedSiteId <= 0) {
			if (count($visible) === 1) {
				return (int)$visible[0]->getId();
			}
			throw new \OCA\SnackCheck\Exception\DomainException(
				'site_required',
				'siteId required when multi-site is enabled',
				422,
			);
		}
		foreach ($visible as $site) {
			if ((int)$site->getId() === $requestedSiteId) {
				return $requestedSiteId;
			}
		}
		throw new \OCA\SnackCheck\Exception\DomainException('foreign_site', 'Site not allowed for this manager', 403);
	}
}
