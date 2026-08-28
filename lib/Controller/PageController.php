<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Controller;

use OCA\SnackCheck\Config\InstanceId;
use OCA\SnackCheck\Db\ConsumptionLogMapper as LogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AdminTotalsService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\BrAggregateService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\MyMonthStatementPresenter;
use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\PulseService;
use OCA\SnackCheck\Service\SettingsSectionCatalog;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\SubsidyService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Support\PeriodDisplay;
use OCA\SnackCheck\Support\SupportUsLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly AccessControlService $access,
		private readonly SettingsService $settings,
		private readonly SiteService $sites,
		private readonly CatalogService $catalog,
		private readonly PeriodService $periods,
		private readonly LogMapper $logs,
		private readonly SubsidyService $subsidy,
		private readonly PulseService $pulse,
		private readonly LicenseService $license,
		private readonly TerminalDeviceService $terminals,
		private readonly HospAllowMapper $hospAllow,
		private readonly PayrollExportService $payroll,
		private readonly AuditService $audit,
		private readonly AdminTotalsService $adminTotals,
		private readonly BrAggregateService $brAggregate,
		private readonly SettingsSectionCatalog $settingsSections,
		private readonly IL10N $l10n,
		private readonly MyMonthStatementPresenter $myMonthStatement,
		private readonly InstanceId $instanceId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse
	{
		return new RedirectResponse($this->urlGenerator->linkToRoute('snackcheck.page.log'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function log(): TemplateResponse
	{
		$user = $this->requireUser();
		$requested = $this->requestSiteId();
		$sitePickRequired = false;
		$siteId = 0;
		try {
			if (!$this->access->isAppAdmin($user) && $this->access->isKitchenManager($user)) {
				$siteId = $this->access->resolveManagedSiteId($user, $requested);
			} else {
				$siteId = $this->sites->requireExplicitSiteId($requested);
			}
		} catch (\OCA\SnackCheck\Exception\DomainException $e) {
			if (in_array($e->errorCode, ['site_required', 'validation_failed'], true)) {
				$sitePickRequired = true;
			} else {
				throw $e;
			}
		}
		$items = $sitePickRequired ? [] : $this->catalog->listActive($siteId);
		$canHosp = $this->settings->isHospitalityEnabled() && $this->hospAllow->isAllowed($user);
		$open = $this->periods->findOpen();
		$mapped = $this->mapLogItems($items);
		return $this->page('log', [
			'items' => $mapped,
			'itemGroups' => $this->groupLogItems($mapped),
			'siteId' => $siteId,
			'sitePickRequired' => $sitePickRequired,
			'canProxy' => $siteId > 0 && $this->access->canManageSite($user, $siteId),
			'hospitalityAllowed' => $canHosp,
			'periodClosed' => $open === null,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myMonth(): TemplateResponse
	{
		$user = $this->requireUser();
		$period = $this->periods->findOpen() ?? $this->periods->findLatestClosed();
		if ($period === null) {
			return $this->page('mymonth', [
				'periodLabel' => '—',
				'periodClosed' => true,
				'lines' => [],
				'freeQty' => 0,
				'grossCents' => 0,
				'subsidyCents' => 0,
				'subsidyAllowanceCents' => $this->settings->getSubsidyAllowanceCents(),
				'deductCents' => 0,
				'showSubsidyStat' => false,
				'breakdownRows' => [],
				'multiSite' => $this->settings->isMultiSiteEnabled(),
			]);
		}
		$lines = $this->logs->findForUserPeriod((int)$period->getId(), $user);
		$multiSite = $this->settings->isMultiSiteEnabled();
		$siteMap = [];
		if ($multiSite) {
			foreach ($this->sites->listActive() as $s) {
				$siteMap[(int)$s->getId()] = $s->getName();
			}
		}
		$lineArr = [];
		$freeQty = 0;
		foreach ($lines as $l) {
			if ($l->getBillingBucket() === 'company_hospitality') {
				continue;
			}
			$free = ((int)$l->getLineTotalCents()) === 0;
			if ($free) {
				$freeQty += (int)$l->getQty();
			}
			$lineArr[] = [
				'line_total_cents' => (int)$l->getLineTotalCents(),
				'billing_bucket' => 'personal',
				'voided' => false,
				'name' => $l->getItemNameSnap(),
				'qty' => $l->getQty(),
				'createdAt' => $l->getCreatedAt()?->format('Y-m-d H:i'),
				'id' => $l->getId(),
				'free' => $free,
				'siteName' => $multiSite ? ($siteMap[(int)$l->getSiteId()] ?? '') : '',
			];
		}
		$subsidyAllowanceCents = $this->settings->getSubsidyAllowanceCents();
		$calc = $this->subsidy->computeForUser($subsidyAllowanceCents, $lineArr);
		$grossCents = (int)$calc['gross_cents'];
		$subsidyCents = (int)$calc['subsidy_cents'];
		$deductCents = (int)$calc['deduct_cents'];
		return $this->page('mymonth', [
			'periodLabel' => PeriodDisplay::format((string)$period->getLabel()),
			'periodClosed' => $period->getState() !== 'open',
			'lines' => $lineArr,
			'freeQty' => $freeQty,
			'grossCents' => $grossCents,
			'subsidyCents' => $subsidyCents,
			'subsidyAllowanceCents' => $subsidyAllowanceCents,
			'deductCents' => $deductCents,
			'showSubsidyStat' => $this->myMonthStatement->showSubsidy($subsidyAllowanceCents, $subsidyCents),
			'breakdownRows' => $this->myMonthStatement->breakdownRows($this->l10n, $grossCents, $subsidyCents, $subsidyAllowanceCents),
			'multiSite' => $multiSite,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catalog(): TemplateResponse
	{
		$user = $this->requireUser();
		$this->assertManager($user);
		$siteId = $this->access->resolveManagedSiteId($user, $this->requestSiteId());
		$items = $this->catalog->listAll($siteId);
		$multiSite = $this->settings->isMultiSiteEnabled();
		$otherSites = [];
		if ($multiSite) {
			foreach ($this->access->sitesVisibleTo($user) as $site) {
				if ((int)$site->getId() !== $siteId) {
					$otherSites[] = $site;
				}
			}
		}
		return $this->page('catalog', [
			'siteId' => $siteId,
			'items' => $items,
			'empty' => count($items) === 0,
			'multiSite' => $multiSite,
			'otherSites' => $otherSites,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pulse(): TemplateResponse
	{
		$user = $this->requireUser();
		$this->assertManager($user);
		$siteId = $this->access->resolveManagedSiteId($user, $this->requestSiteId());
		$category = (string)($this->request->getParam('category') ?? '');
		$data = $this->pulse->buildForSite($siteId, $category !== '' ? $category : null);
		return $this->page('pulse', [
			'siteId' => $siteId,
			'pulse' => $data,
			'category' => $category !== '' ? $category : 'all',
			'categories' => ['all', 'drink', 'snack', 'alcohol', 'other'],
			'paceWindowDays' => $this->settings->getPaceWindowDays(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function periods(): TemplateResponse
	{
		$user = $this->requireUser();
		$this->access->assertAppAdmin($user);
		return $this->page('periods', [
			'periods' => $this->periods->listAll(),
			'open' => $this->periods->findOpen(),
			'siteNote' => $this->settings->isMultiSiteEnabled(),
			'sites' => $this->sites->listActive(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function hospitality(): TemplateResponse|RedirectResponse
	{
		$user = $this->requireUser();
		$this->assertManager($user);
		if (!$this->settings->isHospitalityEnabled()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('snackcheck.page.log'));
		}
		$requested = (int)($this->request->getParam('periodId') ?: 0);
		if ($requested > 0) {
			$period = $this->periods->get($requested);
		} else {
			$period = $this->periods->findOpen() ?? $this->periods->findLatestClosed();
		}
		if ($period === null) {
			return $this->page('hospitality', $this->hospitalityView($user, null, [], null));
		}
		$siteFilter = null;
		if (!$this->access->isAppAdmin($user)) {
			$siteFilter = $this->access->resolveManagedSiteId($user, $this->requestSiteId());
		} elseif ($this->requestSiteId()) {
			$siteFilter = $this->requestSiteId();
		}
		$rows = $this->payroll->buildHospitalityRows((int)$period->getId(), $siteFilter);
		return $this->page('hospitality', $this->hospitalityView($user, $period, $rows, $siteFilter));
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	private function hospitalityView(string $viewerUid, ?object $period, array $rows, ?int $exportSiteId): array
	{
		$companyUid = $this->settings->getHospitalityCompanyUserId();
		// Allowlist names are workplace PII — app admins only (site managers see bookings for their site).
		$allowDisplay = [];
		$allowIds = [];
		if ($this->access->isAppAdmin($viewerUid)) {
			$allowIds = $this->hospAllow->listUserIds();
			foreach ($allowIds as $uid) {
				$allowDisplay[] = $this->displayNameFor((string)$uid);
			}
		}
		return [
			'companyUserId' => $companyUid,
			'companyUserDisplay' => $companyUid !== '' ? $this->displayNameFor($companyUid) : '',
			'period' => $period,
			'periods' => $this->periods->listAll(),
			'rows' => $rows,
			'allowlist' => $allowIds,
			'allowlistDisplay' => $allowDisplay,
			'exportSiteId' => $exportSiteId,
			'multiSite' => $this->settings->isMultiSiteEnabled(),
		];
	}

	private function displayNameFor(string $userId): string
	{
		$user = $this->userManager->get($userId);
		$dn = $user?->getDisplayName();
		return is_string($dn) && $dn !== '' ? $dn : $userId;
	}

	/**
	 * @param list<mixed> $uids
	 * @return list<array{id:string,displayName:string}>
	 */
	private function userChips(array $uids): array
	{
		$out = [];
		foreach ($uids as $uid) {
			$uid = trim((string)$uid);
			if ($uid === '') {
				continue;
			}
			$out[] = ['id' => $uid, 'displayName' => $this->displayNameFor($uid)];
		}
		return $out;
	}

	/**
	 * @param list<mixed> $gids
	 * @return list<array{id:string,displayName:string}>
	 */
	private function groupChips(array $gids): array
	{
		$out = [];
		foreach ($gids as $gid) {
			$gid = trim((string)$gid);
			if ($gid === '') {
				continue;
			}
			$g = $this->groupManager->get($gid);
			$dn = $g !== null ? (string)$g->getDisplayName() : '';
			$out[] = ['id' => $gid, 'displayName' => $dn !== '' ? $dn : $gid];
		}
		return $out;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function sites(): TemplateResponse|RedirectResponse
	{
		$user = $this->requireUser();
		$this->access->assertAppAdmin($user);
		if (!$this->settings->isMultiSiteEnabled()) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('snackcheck.page.settings', ['section' => 'benefits']));
		}
		$sites = [];
		foreach ($this->sites->listAll() as $site) {
			$managerUids = $this->sites->managerUids($site);
			$sites[] = [
				'id' => $site->getId(),
				'name' => $site->getName(),
				'code' => $site->getCode(),
				'active' => (int)$site->getActive() === 1,
				'managers' => $managerUids,
				'managerChips' => $this->userChips($managerUids),
			];
		}
		return $this->page('sites', ['siteRows' => $sites]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function users(): TemplateResponse
	{
		$user = $this->requireUser();
		$this->assertManager($user);
		$siteIds = null;
		if (!$this->access->isAppAdmin($user)) {
			$siteIds = array_map(static fn ($s) => (int)$s->getId(), $this->access->sitesVisibleTo($user));
		}
		$requested = $this->requestSiteId();
		$sitePickRequired = false;
		$siteId = 0;
		try {
			if (!$this->access->isAppAdmin($user) && $this->access->isKitchenManager($user)) {
				$siteId = $this->access->resolveManagedSiteId($user, $requested);
			} else {
				$siteId = $this->sites->requireExplicitSiteId($requested);
			}
		} catch (\OCA\SnackCheck\Exception\DomainException $e) {
			if (in_array($e->errorCode, ['site_required', 'validation_failed'], true)) {
				$sitePickRequired = true;
			} else {
				throw $e;
			}
		}
		$open = $this->periods->findOpen();
		$periodClosed = $open === null;
		$canProxy = $siteId > 0 && !$sitePickRequired && $this->access->canManageSite($user, $siteId);
		$proxyItems = [];
		if ($canProxy && !$periodClosed) {
			$proxyItems = $this->mapLogItems($this->catalog->listActive($siteId));
		}
		return $this->page('users', array_merge(
			$this->adminTotals->buildForOpenPeriod(null, $siteIds),
			[
				'canProxy' => $canProxy,
				'siteId' => $siteId,
				'sitePickRequired' => $sitePickRequired,
				'periodClosed' => $periodClosed,
				'proxyItems' => $proxyItems,
			]
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function brReport(): TemplateResponse
	{
		$user = $this->requireUser();
		$this->access->assertAppAdmin($user);
		$periodId = (int)($this->request->getParam('periodId') ?: 0);
		$open = $this->periods->findOpen();
		if ($periodId <= 0) {
			if ($open === null) {
				$closed = $this->periods->findLatestClosed();
				$periodId = $closed !== null ? (int)$closed->getId() : 0;
			} else {
				$periodId = (int)$open->getId();
			}
		}
		$data = $periodId > 0
			? $this->brAggregate->buildForPeriod($periodId)
			: ['periodLabel' => '—', 'byCategory' => [], 'byItem' => []];
		return $this->page('brreport', [
			'report' => $data,
			'periodId' => $periodId,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function audit(): TemplateResponse
	{
		$user = $this->requireUser();
		$this->access->assertAppAdmin($user);
		$events = [];
		foreach ($this->audit->recent(100) as $e) {
			$events[] = [
				'createdAt' => $e->getCreatedAt()?->format('Y-m-d H:i'),
				'actor' => $e->getActorUid(),
				'action' => $e->getAction(),
				'entityType' => $e->getEntityType(),
				'entityId' => $e->getEntityId(),
			];
		}
		return $this->page('audit', ['events' => $events]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsIndex(): RedirectResponse
	{
		$user = $this->requireUser();
		$this->access->assertAppAdmin($user);
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'snackcheck.page.settings',
			['section' => SettingsSectionCatalog::DEFAULT_SECTION],
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section): TemplateResponse|RedirectResponse|NotFoundResponse
	{
		$user = $this->requireUser();
		$this->access->assertAppAdmin($user);
		if ($section === 'periods') {
			return new RedirectResponse($this->urlGenerator->linkToRoute('snackcheck.page.periods'));
		}
		if ($section === 'sites') {
			return new RedirectResponse($this->urlGenerator->linkToRoute('snackcheck.page.sites'));
		}
		if (!$this->settingsSections->isSection($section)) {
			return new NotFoundResponse();
		}
		$all = $this->settings->getAll();
		$hospIds = $this->hospAllow->listUserIds();
		$companyUid = trim((string)($all['hospitalityCompanyUserId'] ?? ''));

		$sectionLabels = [];
		$sectionUrls = [];
		foreach (SettingsSectionCatalog::SECTIONS as $sectionId) {
			$sectionLabels[$sectionId] = $this->settingsSections->navLabel($this->l10n, $sectionId);
			$sectionUrls[$sectionId] = $this->urlGenerator->linkToRoute('snackcheck.page.settings', ['section' => $sectionId]);
		}

		$payload = [
			'section' => $section,
			'settingsSection' => $section,
			'settingsSectionLabels' => $sectionLabels,
			'urls' => ['settingsSections' => $sectionUrls],
			'pageTitle' => $this->settingsSections->label($this->l10n, $section),
			'pageHelp' => $this->settingsSections->help($this->l10n, $section),
			'pageIcon' => 'settings',
			'settings' => $all,
			'hospAllowlist' => $hospIds,
			'accessUserChips' => $this->userChips(is_array($all['accessUsers'] ?? null) ? $all['accessUsers'] : []),
			'accessGroupChips' => $this->groupChips(is_array($all['accessGroups'] ?? null) ? $all['accessGroups'] : []),
			'appAdminChips' => $this->userChips(is_array($all['appAdmins'] ?? null) ? $all['appAdmins'] : []),
			'hospAllowChips' => $this->userChips($hospIds),
			'hospCompanyChips' => $companyUid !== '' ? $this->userChips([$companyUid]) : [],
			'license' => $this->license->getLicenseSummary(),
			'terminals' => $this->terminals->listActive(),
			'terminalLimit' => $this->terminals->getDeviceLimit(),
			'terminalUsed' => $this->terminals->getActiveCount(),
			'sites' => $this->sites->listActive(),
		];
		if ($section === 'license') {
			$lang = method_exists($this->l10n, 'getLanguageCode') ? (string)$this->l10n->getLanguageCode() : 'en';
			$supportLinks = new SupportUsLinks(
				'SnackCheck',
				true,
				$this->urlGenerator->linkToRouteAbsolute('snackcheck.page.settings', ['section' => 'license']) . '#snk-license-key',
			);
			$payload['productsUrl'] = $supportLinks->productsUrl();
			$payload['licenseRenewMailto'] = $supportLinks->licenseMailto($lang);
			$payload['instanceId'] = $this->instanceId->get();
		}
		if ($section === 'support') {
			$payload['supportUsLinks'] = new SupportUsLinks(
				'SnackCheck',
				true,
				$this->urlGenerator->linkToRouteAbsolute('snackcheck.page.settings', ['section' => 'license']) . '#snk-license-key',
			);
		}

		return $this->page('settings', $payload);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shelf(int $itemId): TemplateResponse
	{
		$user = $this->requireUser();
		// Argus MF-A04: assert app access BEFORE catalog probe — otherwise NC users
		// denied SnackCheck can distinguish missing/inactive (404) vs active SKU (403).
		$this->access->assertAccess($user);
		$item = $this->catalog->get($itemId);
		// Inactive / archived: same 404 as missing — no shelf deep-link probe of retired SKUs.
		if ((int)$item->getActive() !== 1) {
			throw new \OCA\SnackCheck\Exception\DomainException('not_found', 'Item not found', 404);
		}
		$canHosp = $this->settings->isHospitalityEnabled() && $this->hospAllow->isAllowed($user);
		$open = $this->periods->findOpen();
		return $this->page('log', [
			'items' => [[
				'id' => $item->getId(),
				'name' => $item->getName(),
				'priceCents' => $item->getPriceCents(),
				'category' => $item->getCategory(),
				'free' => ((int)$item->getPriceCents()) === 0,
			]],
			'siteId' => $item->getSiteId(),
			'canProxy' => $this->access->canManageSite($user, (int)$item->getSiteId()),
			'hospitalityAllowed' => $canHosp,
			'periodClosed' => $open === null,
			'shelfFocus' => true,
			'shelfItemName' => $item->getName(),
		]);
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function page(string $pageId, array $params): TemplateResponse
	{
		$user = $this->requireUser();
		$this->access->assertAccess($user);
		Util::addStyle('snackcheck', 'app');
		Util::addScript('snackcheck', 'app');
		Util::addScript('snackcheck', 'common/app-feedback');
		$params['pageId'] = $pageId;
		$params['userId'] = $user;
		$params['urlGenerator'] = $this->urlGenerator;
		$params['isAppAdmin'] = $this->access->isAppAdmin($user);
		$params['isManager'] = $this->access->isKitchenManager($user);
		$params['multiSite'] = $this->settings->isMultiSiteEnabled();
		$params['hospitalityEnabled'] = $this->settings->isHospitalityEnabled();
		$visibleSites = $this->settings->isMultiSiteEnabled()
			? ($this->access->isAppAdmin($user) || $this->access->isKitchenManager($user)
				? $this->access->sitesVisibleTo($user)
				: $this->sites->listActive())
			: [];
		// Employees (non-managers) may pick any active site for logging when Y ON.
		if ($this->settings->isMultiSiteEnabled() && !$this->access->isKitchenManager($user)) {
			$visibleSites = $this->sites->listActive();
		}
		$params['sites'] = $params['sites'] ?? $visibleSites;
		$requested = $this->requestSiteId();
		// Never pretend a site is selected when logging requires an explicit pick.
		if (!empty($params['sitePickRequired'])) {
			$params['currentSiteId'] = 0;
		} elseif ($this->access->isKitchenManager($user) && !$this->access->isAppAdmin($user) && $this->settings->isMultiSiteEnabled()) {
			try {
				$params['currentSiteId'] = $this->access->resolveManagedSiteId($user, $requested);
			} catch (\Throwable) {
				$params['currentSiteId'] = $visibleSites !== [] ? (int)$visibleSites[0]->getId() : $this->sites->getDefaultSiteId();
			}
		} else {
			$params['currentSiteId'] = $requested ?? $this->sites->getDefaultSiteId();
		}
		$params['nav'] = $this->navFor($user);
		return new TemplateResponse('snackcheck', 'main', $params);
	}

	/**
	 * @return list<array{id:string,label:string,route:string,group:string,icon:string,hint:string}>
	 */
	private function navFor(string $userId): array
	{
		$nav = [
			['id' => 'log', 'label' => 'Log', 'route' => 'snackcheck.page.log', 'group' => 'me', 'icon' => 'utensils', 'hint' => 'Tap a snack'],
			['id' => 'mymonth', 'label' => 'My month', 'route' => 'snackcheck.page.myMonth', 'group' => 'me', 'icon' => 'calendar', 'hint' => 'Payroll preview'],
		];
		if ($this->access->isKitchenManager($userId)) {
			$nav[] = ['id' => 'pulse', 'label' => 'Kitchen overview', 'route' => 'snackcheck.page.pulse', 'group' => 'kitchen', 'icon' => 'activity', 'hint' => 'Stock & restock'];
			$nav[] = ['id' => 'catalog', 'label' => 'Catalog', 'route' => 'snackcheck.page.catalog', 'group' => 'kitchen', 'icon' => 'package', 'hint' => 'Items & prices'];
			$nav[] = ['id' => 'users', 'label' => 'Users / totals', 'route' => 'snackcheck.page.users', 'group' => 'kitchen', 'icon' => 'users', 'hint' => 'Totals & book for others'];
		}
		if ($this->access->isAppAdmin($userId)) {
			$nav[] = ['id' => 'periods', 'label' => 'Periods', 'route' => 'snackcheck.page.periods', 'group' => 'money', 'icon' => 'calendar-range', 'hint' => 'Open & close'];
			$nav[] = ['id' => 'brreport', 'label' => 'Payroll summary', 'route' => 'snackcheck.page.brReport', 'group' => 'money', 'icon' => 'file-text', 'hint' => 'Payroll export'];
			if ($this->settings->isMultiSiteEnabled()) {
				$nav[] = ['id' => 'sites', 'label' => 'Sites', 'route' => 'snackcheck.page.sites', 'group' => 'admin', 'icon' => 'building-2', 'hint' => 'Kitchens'];
			}
			$nav[] = ['id' => 'audit', 'label' => 'Audit', 'route' => 'snackcheck.page.audit', 'group' => 'admin', 'icon' => 'clipboard-list', 'hint' => 'Change log'];
			$nav[] = ['id' => 'settings', 'label' => 'Settings', 'route' => 'snackcheck.page.settingsIndex', 'group' => 'admin', 'icon' => 'settings', 'hint' => 'Access & rules'];
		}
		if ($this->settings->isHospitalityEnabled() && $this->access->isKitchenManager($userId)) {
			$nav[] = ['id' => 'hospitality', 'label' => 'Hospitality', 'route' => 'snackcheck.page.hospitality', 'group' => 'money', 'icon' => 'coffee', 'hint' => 'Company treats'];
		}
		return $nav;
	}

	/**
	 * @param list<\OCA\SnackCheck\Db\CatalogItem> $items
	 * @return list<array{id:int,name:string,priceCents:int,category:?string,tags:list<string>,free:bool,hasImage:bool,imageUrl:?string,icon:string}>
	 */
	private function mapLogItems(array $items): array
	{
		$out = [];
		foreach ($items as $i) {
			$tags = [];
			$raw = $i->getTagsJson();
			if (is_string($raw) && $raw !== '') {
				try {
					$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
					if (is_array($decoded)) {
						$tags = array_values(array_filter($decoded, 'is_string'));
					}
				} catch (\JsonException) {
					$tags = [];
				}
			}
			$hasImage = \OCA\SnackCheck\Service\CatalogImageService::hasImage($i);
			$imageUrl = null;
			if ($hasImage) {
				$imageUrl = $this->urlGenerator->linkToRoute('snackcheck.api.catalogImage', ['id' => (int)$i->getId()])
					. '?v=' . rawurlencode((string)$i->getImageName());
			}
			$out[] = [
				'id' => (int)$i->getId(),
				'name' => (string)$i->getName(),
				'priceCents' => (int)$i->getPriceCents(),
				'category' => $i->getCategory(),
				'tags' => $tags,
				'free' => ((int)$i->getPriceCents()) === 0,
				'hasImage' => $hasImage,
				'imageUrl' => $imageUrl,
				'icon' => \OCA\SnackCheck\Service\IconCatalog::forCategory($i->getCategory()),
			];
		}
		return $out;
	}

	/**
	 * @param list<array{id:int,name:string,priceCents:int,category:?string,tags:list<string>,free:bool,hasImage:bool,imageUrl:?string,icon:string}> $items
	 * @return list<array{category:string,items:list<array{id:int,name:string,priceCents:int,category:?string,tags:list<string>,free:bool,hasImage:bool,imageUrl:?string,icon:string}>}>
	 */
	private function groupLogItems(array $items): array
	{
		$buckets = [];
		foreach ($items as $item) {
			$cat = (string)($item['category'] ?? '');
			if ($cat === '' || !in_array($cat, CatalogService::CATEGORIES, true)) {
				$cat = 'other';
			}
			if (!isset($buckets[$cat])) {
				$buckets[$cat] = [];
			}
			$buckets[$cat][] = $item;
		}
		$groups = [];
		foreach (CatalogService::CATEGORIES as $cat) {
			if (!empty($buckets[$cat])) {
				$groups[] = ['category' => $cat, 'items' => $buckets[$cat]];
			}
		}
		return $groups;
	}

	private function requireUser(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'Login required', 403);
		}
		return $user->getUID();
	}

	private function assertManager(string $userId): void
	{
		if (!$this->access->isKitchenManager($userId)) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'Manager required', 403);
		}
	}

	private function requestSiteId(): ?int
	{
		$raw = $this->request->getParam('siteId');
		if ($raw === null || $raw === '') {
			return null;
		}
		return (int)$raw;
	}
}
