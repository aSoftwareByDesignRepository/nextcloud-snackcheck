<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Controller;

use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AdminTotalsService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\BrAggregateService;
use OCA\SnackCheck\Service\ComplimentaryExportService;
use OCA\SnackCheck\Service\DigestMailService;
use OCA\SnackCheck\Service\ShelfQrService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\LicenseEnforcementService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\PulseService;
use OCA\SnackCheck\Service\RateLimitService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\CsvExportBuilder;
use OCA\SnackCheck\Service\SimplePdfBuilder;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\SubsidyService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Service\UnlockService;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class ApiController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessControlService $access,
		private readonly ConsumptionLogService $logs,
		private readonly CatalogService $catalog,
		private readonly PeriodService $periods,
		private readonly SiteService $sites,
		private readonly SettingsService $settings,
		private readonly LicenseService $license,
		private readonly TerminalDeviceService $terminals,
		private readonly LicenseEnforcementService $enforcement,
		private readonly PayrollExportService $payroll,
		private readonly UnlockService $unlock,
		private readonly HospAllowMapper $hospAllow,
		private readonly PulseService $pulse,
		private readonly RateLimitService $rateLimit,
		private readonly IUserManager $userManager,
		private readonly ConsumptionLogMapper $logMapper,
		private readonly SubsidyService $subsidy,
		private readonly AdminTotalsService $adminTotals,
		private readonly AuditService $auditService,
		private readonly DigestMailService $digestMail,
		private readonly \OCP\IGroupManager $groupManager,
		private readonly BrAggregateService $brAggregate,
		private readonly ComplimentaryExportService $complimentary,
		private readonly ShelfQrService $shelfQr,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function createLog(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAccess($user);
			$this->rateLimit->assertUserLog($user);
			$siteId = $this->sites->requireExplicitSiteId((int)$this->request->getParam('siteId') ?: null);
			$mode = (string)($this->request->getParam('mode') ?? 'self');
			$isKitchenAdmin = $this->access->isAppAdmin($user)
				|| $this->access->canManageSite($user, $siteId);
			if ($mode === 'proxy') {
				$this->access->assertCanManageSite($user, $siteId);
			}
			$result = $this->logs->create([
				'itemId' => (int)$this->request->getParam('itemId'),
				'qty' => (int)($this->request->getParam('qty') ?? 1),
				'idempotencyKey' => (string)($this->request->getHeader('Idempotency-Key') ?: $this->request->getParam('idempotencyKey') ?? ''),
				'siteId' => $siteId,
				'actorUserId' => $user,
				'source' => 'web',
				'mode' => $mode,
				'targetUserId' => $this->request->getParam('targetUserId'),
				'proxyReason' => $this->request->getParam('proxyReason'),
				'hospitalityReason' => $this->request->getParam('hospitalityReason'),
				'isKitchenAdmin' => $isKitchenAdmin,
			]);
			$log = $result['log'];
			return $this->ok([
				'id' => $log->getId(),
				'lineTotalCents' => $log->getLineTotalCents(),
				'replay' => $result['replay'],
			], $result['httpStatus']);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function undoLog(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAccess($user);
			$log = $this->logs->selfUndo($id, $user);
			return $this->ok(['id' => $log->getId()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function applyStarter(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$siteId = $this->access->resolveManagedSiteId($user, (int)$this->request->getParam('siteId') ?: null);
			$items = $this->catalog->applyStarterDe($siteId, $user);
			return $this->ok(['count' => count($items)]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function createCatalogItem(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$siteId = $this->access->resolveManagedSiteId($user, (int)$this->request->getParam('siteId') ?: null);
			$tagsRaw = $this->request->getParam('tags');
			$tags = null;
			if (is_array($tagsRaw)) {
				$tags = $tagsRaw;
			} elseif (is_string($tagsRaw) && $tagsRaw !== '') {
				$tags = preg_split('/[\s,;]+/', $tagsRaw) ?: [];
			}
			$item = $this->catalog->create(
				$siteId,
				(string)$this->request->getParam('name'),
				(int)$this->request->getParam('priceCents'),
				$user,
				(string)($this->request->getParam('category') ?? 'other'),
				null,
				$this->request->getParam('parLevel') === null || $this->request->getParam('parLevel') === ''
					? null
					: (int)$this->request->getParam('parLevel'),
				$this->request->getParam('onHand') === null || $this->request->getParam('onHand') === ''
					? null
					: (int)$this->request->getParam('onHand'),
				$tags,
			);
			return $this->ok(['id' => $item->getId()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function restockItem(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$existing = $this->catalog->get($id);
			$this->access->assertCanManageSite($user, (int)$existing->getSiteId());
			$item = $this->catalog->restock($id, (int)$this->request->getParam('qty'), $user);
			return $this->ok(['onHand' => $item->getOnHand()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function closePeriod(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$result = $this->periods->close($id, $user, $this->truthy($this->request->getParam('confirm')));
			return $this->ok(['state' => $result['period']->getState(), 'warnings' => $result['warnings']]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function reopenPeriod(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$p = $this->periods->reopen($id, $user, (string)$this->request->getParam('reason'));
			return $this->ok(['state' => $p->getState()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function openNextPeriod(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$p = $this->periods->openNextPeriod($user);
			return $this->ok(['id' => $p->getId(), 'label' => $p->getLabel(), 'state' => $p->getState()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadPayroll(int $id): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$this->assertNotCrossSiteDownload();
			$siteRaw = $this->request->getParam('siteId');
			$siteFilter = ($siteRaw === null || $siteRaw === '' || $siteRaw === 'all')
				? null
				: (int)$siteRaw;
			$pkg = $this->payroll->buildPersonalPackage($id, $siteFilter);
			if (!$pkg['reconcileOk']) {
				return $this->fail('validation_failed', 422, 'Payroll reconcile failed');
			}
			$format = (string)($this->request->getParam('format') ?? 'xlsx');
			if ($format === 'csv') {
				$csv = $this->payroll->toCsv($pkg['lines'], [
					'period_label','user_id','user_display_name','item_name','qty',
					'unit_price_eur','line_total_eur','logged_at','source','site_code','site_name',
				]);
				return new DataDownloadResponse($csv, 'snackcheck-payroll.csv', 'text/csv');
			}
			$xlsx = $this->payroll->toXlsx($pkg);
			return new DataDownloadResponse($xlsx, 'snackcheck-payroll.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadHospitality(int $id): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$this->assertNotCrossSiteDownload();
			$siteRaw = $this->request->getParam('siteId');
			$siteFilter = ($siteRaw === null || $siteRaw === '' || $siteRaw === 'all')
				? null
				: (int)$siteRaw;
			if ($siteFilter !== null) {
				$this->access->assertCanManageSite($user, $siteFilter);
			} elseif (!$this->access->isAppAdmin($user)) {
				$siteFilter = $this->access->resolveManagedSiteId($user, null);
			}
			$rows = $this->payroll->buildHospitalityRows($id, $siteFilter);
			$csv = $this->payroll->toCsv($rows, [
				'logged_at','actor_uid','actor_display','company_user_id','item_name','qty',
				'unit_price_cents','line_total_cents','reason','source','site_code','site_name',
			]);
			return new DataDownloadResponse($csv, 'snackcheck-hospitality.csv', 'text/csv');
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function applyLicense(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$ok = $this->license->applyLicenseKey((string)$this->request->getParam('licenseKey'));
			if (!$ok) {
				return $this->fail($this->license->getLastApplyErrorCode() ?: 'INVALID_FORMAT', 422);
			}
			$summary = $this->license->getLicenseSummary();
			$trimmed = $this->enforcement->trimTerminalsToLimit((int)($summary['terminalDevices'] ?? 0));
			return $this->ok(['license' => $summary, 'trimmed' => $trimmed]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function registerTerminal(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$siteId = $this->sites->requireExplicitSiteId((int)$this->request->getParam('siteId') ?: null);
			$result = $this->terminals->register($user, (string)$this->request->getParam('label'), $siteId);
			if (!$result['ok']) {
				$status = ($result['error'] ?? '') === 'terminal_limit_reached' ? 422 : 400;
				return $this->fail((string)$result['error'], $status);
			}
			return $this->ok($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function revokeTerminal(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$result = $this->terminals->revoke((int)$this->request->getParam('deviceId'), $user);
			if (!$result['ok']) {
				return $this->fail((string)$result['error'], 404);
			}
			return $this->ok();
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);

			// Aristoteles MF: validate the full payload before any appconfig / allowlist write.
			$subsidyCents = null;
			if ($this->request->getParam('subsidyAllowanceEuro') !== null) {
				$subsidyCents = $this->parseEuroToCents((string)$this->request->getParam('subsidyAllowanceEuro'));
			}
			if ($this->request->getParam('subsidyAllowanceCents') !== null) {
				$rawCents = $this->request->getParam('subsidyAllowanceCents');
				if (is_string($rawCents) && preg_match('/[eE]/', $rawCents) === 1) {
					return $this->fail('validation_failed', 422, 'Invalid subsidy amount');
				}
				$cents = (int)$rawCents;
				if ($cents < 0 || $cents > 100_000_000) {
					return $this->fail('validation_failed', 422, 'Invalid subsidy amount');
				}
				$subsidyCents = $cents;
			}

			$hospOn = null;
			$hospCompany = null;
			/** @var list<string>|null $hospAllowList */
			$hospAllowList = null;
			if ($this->request->getParam('hospitalityEnabled') !== null) {
				$hospOn = $this->truthy($this->request->getParam('hospitalityEnabled'));
				if ($hospOn) {
					$company = (string)($this->request->getParam('hospitalityCompanyUserId') ?? $this->settings->getHospitalityCompanyUserId());
					$allow = $this->request->getParam('hospitalityAllowedUserIds');
					$allowList = is_array($allow) ? $allow : (is_string($allow) ? $this->csvList($allow) : $this->hospAllow->listUserIds());
					if (trim($company) === '' || $allowList === []) {
						return $this->fail('validation_failed', 422, 'Company user and allowlist required');
					}
					$hospCompany = $this->requireExistingUser($company);
					$hospAllowList = $this->requireExistingUsers($allowList);
				}
			}

			$companyUpdate = null;
			if ($this->request->getParam('hospitalityCompanyUserId') !== null) {
				$companyUpdate = trim((string)$this->request->getParam('hospitalityCompanyUserId'));
			}
			$allowUpdate = null;
			if ($this->request->getParam('hospitalityAllowedUserIds') !== null) {
				$allow = $this->request->getParam('hospitalityAllowedUserIds');
				$allowUpdate = is_array($allow) ? $allow : $this->csvList((string)$allow);
				$allowUpdate = array_values(array_filter(array_map('strval', $allowUpdate), static fn (string $u) => $u !== ''));
			}

			$multiSiteOn = null;
			if ($this->request->getParam('multiSiteEnabled') !== null) {
				$multiSiteOn = $this->truthy($this->request->getParam('multiSiteEnabled'));
				if (!$multiSiteOn && !$this->sites->canDisableMultiSite()) {
					return $this->fail('multi_site_in_use', 422, 'Disable blocked while multiple sites active');
				}
			}

			$privacyTotalsOnly = $this->request->getParam('privacyTotalsOnly') !== null
				? $this->truthy($this->request->getParam('privacyTotalsOnly'))
				: null;
			$paceWindowDays = $this->request->getParam('paceWindowDays') !== null
				? (int)$this->request->getParam('paceWindowDays')
				: null;
			$restockHorizonDays = $this->request->getParam('restockHorizonDays') !== null
				? (int)$this->request->getParam('restockHorizonDays')
				: null;
			$accessMode = $this->request->getParam('accessMode') !== null
				? (string)$this->request->getParam('accessMode')
				: null;
			$accessUsers = null;
			if ($this->request->getParam('accessUsers') !== null) {
				$users = $this->request->getParam('accessUsers');
				$accessUsers = $this->requireExistingUsers(is_array($users) ? $users : $this->csvList((string)$users));
			}
			$accessGroups = null;
			if ($this->request->getParam('accessGroups') !== null) {
				$groups = $this->request->getParam('accessGroups');
				$accessGroups = $this->requireExistingGroups(is_array($groups) ? $groups : $this->csvList((string)$groups));
			}
			$appAdmins = null;
			if ($this->request->getParam('appAdmins') !== null) {
				$admins = $this->request->getParam('appAdmins');
				$appAdmins = $this->requireExistingUsers(is_array($admins) ? $admins : $this->csvList((string)$admins));
			}
			$personalDigestEnabled = $this->request->getParam('personalDigestEnabled') !== null
				? $this->truthy($this->request->getParam('personalDigestEnabled'))
				: null;
			$personalDigestSkipZero = $this->request->getParam('personalDigestSkipZero') !== null
				? $this->truthy($this->request->getParam('personalDigestSkipZero'))
				: null;
			$weeklyTopUpEmail = $this->request->getParam('weeklyTopUpEmail') !== null
				? $this->truthy($this->request->getParam('weeklyTopUpEmail'))
				: null;
			$personalDigestDaysBefore = $this->request->getParam('personalDigestDaysBefore') !== null
				? (int)$this->request->getParam('personalDigestDaysBefore')
				: null;

			// Projected hospitality state after this request (before writes).
			$projectedOn = $hospOn ?? $this->settings->isHospitalityEnabled();
			$projectedCompany = $hospCompany
				?? ($companyUpdate !== null ? $companyUpdate : $this->settings->getHospitalityCompanyUserId());
			$projectedAllow = $hospAllowList
				?? ($allowUpdate !== null ? $allowUpdate : $this->hospAllow->listUserIds());
			if ($projectedOn) {
				if (trim((string)$projectedCompany) === '' || $projectedAllow === []) {
					return $this->fail('validation_failed', 422, 'Company user and allowlist required while hospitality is enabled');
				}
				if ($companyUpdate !== null && $companyUpdate !== '') {
					$companyUpdate = $this->requireExistingUser($companyUpdate);
					$projectedCompany = $companyUpdate;
				} elseif ($hospCompany === null && $this->userManager->get((string)$projectedCompany) === null) {
					return $this->fail('validation_failed', 422, 'Company user and allowlist required while hospitality is enabled');
				}
				if ($allowUpdate !== null) {
					$allowUpdate = $this->requireExistingUsers($allowUpdate);
					$projectedAllow = $allowUpdate;
				}
			}

			// --- apply (no validation returns after this point) ---
			if ($subsidyCents !== null) {
				$this->settings->setSubsidyAllowanceCents($subsidyCents);
			}
			if ($hospOn !== null) {
				if ($hospOn) {
					$this->settings->setHospitalityCompanyUserId((string)$hospCompany);
					$this->hospAllow->replaceAll($hospAllowList ?? [], $user, new \DateTimeImmutable());
				}
				$this->settings->setHospitalityEnabled($hospOn);
			}
			if ($multiSiteOn !== null) {
				$this->settings->setMultiSiteEnabled($multiSiteOn);
			}
			if ($privacyTotalsOnly !== null) {
				$this->settings->setPrivacyTotalsOnly($privacyTotalsOnly);
			}
			if ($paceWindowDays !== null) {
				$this->settings->setPaceWindowDays($paceWindowDays);
			}
			if ($restockHorizonDays !== null) {
				$this->settings->setRestockHorizonDays($restockHorizonDays);
			}
			if ($accessMode !== null) {
				$this->settings->setAccessMode($accessMode);
			}
			if ($accessUsers !== null) {
				$this->settings->setAccessUsers($accessUsers);
			}
			if ($accessGroups !== null) {
				$this->settings->setAccessGroups($accessGroups);
			}
			if ($appAdmins !== null) {
				$this->settings->setAppAdmins($appAdmins);
			}
			if ($personalDigestEnabled !== null) {
				$this->settings->setPersonalDigestEnabled($personalDigestEnabled);
			}
			if ($personalDigestSkipZero !== null) {
				$this->settings->setPersonalDigestSkipZeroEnabled($personalDigestSkipZero);
			}
			if ($weeklyTopUpEmail !== null) {
				$this->settings->setWeeklyTopUpEmailEnabled($weeklyTopUpEmail);
			}
			if ($personalDigestDaysBefore !== null) {
				$this->digestMail->setDigestDaysBefore($personalDigestDaysBefore);
			}
			$hospEnabledNow = $hospOn ?? $this->settings->isHospitalityEnabled();
			if ($companyUpdate !== null && $hospEnabledNow) {
				$this->settings->setHospitalityCompanyUserId($this->requireExistingUser($companyUpdate));
			}
			if ($allowUpdate !== null && $hospEnabledNow) {
				$this->hospAllow->replaceAll($this->requireExistingUsers($allowUpdate), $user, new \DateTimeImmutable());
			}

			// Invariant already enforced pre-apply — never 422 after writes (would leave partial state).
			return $this->ok($this->settings->getAll());
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function setUnlockPin(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$this->unlock->setPin((string)$this->request->getParam('userId'), (string)$this->request->getParam('pin'), $user);
			return $this->ok();
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function voidLog(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			// Site ACL is re-checked under FOR UPDATE inside ConsumptionLogService::void (isAdmin path).
			$log = $this->logs->void(
				$id,
				$user,
				(string)$this->request->getParam('reason'),
				true,
			);
			return $this->ok(['id' => $log->getId()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function updateCatalogItem(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$existing = $this->catalog->get($id);
			$this->access->assertCanManageSite($user, (int)$existing->getSiteId());
			$fields = [];
			foreach (['name', 'category', 'description'] as $k) {
				if ($this->request->getParam($k) !== null) {
					$fields[$k] = $this->request->getParam($k);
				}
			}
			if ($this->request->getParam('priceCents') !== null) {
				$fields['priceCents'] = (int)$this->request->getParam('priceCents');
			}
			if ($this->request->getParam('parLevel') !== null) {
				$fields['parLevel'] = $this->request->getParam('parLevel') === '' ? null : (int)$this->request->getParam('parLevel');
			}
			if ($this->request->getParam('onHand') !== null) {
				$fields['onHand'] = $this->request->getParam('onHand') === '' ? null : (int)$this->request->getParam('onHand');
			}
			if ($this->request->getParam('sortOrder') !== null) {
				$fields['sortOrder'] = (int)$this->request->getParam('sortOrder');
			}
			if ($this->request->getParam('active') !== null) {
				// Use truthy() — (bool)"0" is true in PHP and would block deactivate.
				$fields['active'] = $this->truthy($this->request->getParam('active'));
			}
			$tags = $this->request->getParam('tags');
			if ($tags !== null) {
				$fields['tags'] = is_array($tags) ? $tags : [];
			}
			$item = $this->catalog->update($id, $fields, $user);
			return $this->ok(['id' => $item->getId()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function deleteCatalogItem(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$existing = $this->catalog->get($id);
			$this->access->assertCanManageSite($user, (int)$existing->getSiteId());
			$item = $this->catalog->softDelete($id, $user);
			return $this->ok(['id' => $item->getId(), 'active' => false]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function setOnHand(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$existing = $this->catalog->get($id);
			$this->access->assertCanManageSite($user, (int)$existing->getSiteId());
			$item = $this->catalog->setOnHand($id, (int)$this->request->getParam('onHand'), $user);
			return $this->ok(['onHand' => $item->getOnHand()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function copyCatalogItem(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$existing = $this->catalog->get($id);
			$this->access->assertCanManageSite($user, (int)$existing->getSiteId());
			$targetSiteId = $this->access->resolveManagedSiteId($user, (int)$this->request->getParam('targetSiteId') ?: null);
			$copy = $this->catalog->copyToSite($id, $targetSiteId, $user);
			return $this->ok(['id' => $copy->getId()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function createSite(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$managers = $this->request->getParam('managerUids');
			$site = $this->sites->create(
				(string)$this->request->getParam('name'),
				(string)$this->request->getParam('code'),
				is_array($managers) ? $this->requireExistingUsers($managers) : [],
			);
			return $this->ok(['id' => $site->getId(), 'code' => $site->getCode()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function updateSite(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$managers = $this->request->getParam('managerUids');
			$active = $this->request->getParam('active');
			$site = $this->sites->update(
				$id,
				$this->request->getParam('name') !== null ? (string)$this->request->getParam('name') : null,
				is_array($managers) ? $this->requireExistingUsers($managers) : null,
				$active !== null ? $this->truthy($active) : null,
			);
			return $this->ok(['id' => $site->getId()]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function markHandedToHr(int $id): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$pkg = $this->payroll->buildPersonalPackage($id);
			if (!$pkg['reconcileOk']) {
				return $this->fail('validation_failed', 422, 'Cannot hand to HR until payroll reconciles');
			}
			$p = $this->periods->markHandedToHr($id, $user);
			return $this->ok([
				'id' => $p->getId(),
				'handedToHrAt' => $p->getHandedToHrAt()?->format('c'),
				'handedToHrBy' => $p->getHandedToHrBy(),
			]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shoppingList(): JSONResponse|DataDownloadResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			// Argus: CSRF-exempt GET — reject cross-site before building the list.
			$this->assertNotCrossSiteDownload();
			$siteId = $this->access->resolveManagedSiteId($user, (int)$this->request->getParam('siteId') ?: null);
			$category = (string)($this->request->getParam('category') ?? '');
			$pulse = $this->pulse->buildForSite($siteId, $category !== '' && $category !== 'all' ? $category : null);
			$format = (string)($this->request->getParam('format') ?? 'json');
			if ($format === 'csv') {
				$rows = [];
				foreach ($pulse['shoppingList'] as $row) {
					$rows[] = [
						(string)($row['name'] ?? ''),
						(string)($row['category'] ?? ''),
						(string)($row['onHand'] ?? ''),
						(string)($row['parLevel'] ?? ''),
						(string)($row['suggestedBuy'] ?? 0),
						!empty($row['complimentary']) ? 'yes' : 'no',
					];
				}
				$csv = \OCA\SnackCheck\Service\CsvExportBuilder::build(
					['item_name', 'category', 'on_hand', 'par_level', 'suggested_buy', 'complimentary'],
					$rows,
				);
				return new DataDownloadResponse($csv, 'snackcheck-shopping-list.csv', 'text/csv');
			}
			if ($format === 'html') {
				$html = \OCA\SnackCheck\Service\ShoppingListHtmlBuilder::build(
					$pulse['shoppingList'],
					'Shopping list',
				);
				return new DataDownloadResponse($html, 'snackcheck-shopping-list.html', 'text/html; charset=UTF-8');
			}
			return $this->ok($pulse['shoppingList']);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function searchUsers(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$q = trim((string)($this->request->getParam('q') ?? ''));
			$limit = min(50, max(1, (int)($this->request->getParam('limit') ?? 20)));
			// scope=directory: ACL editors / site managers / unlock — must find users not yet allowlisted.
			// Only app admins may enumerate the full NC directory (Argus MF — PII least privilege).
			// scope=access (default): proxy / colleague pickers — only people who can use SnackCheck.
			$scope = strtolower(trim((string)($this->request->getParam('scope') ?? 'access')));
			$directory = $scope === 'directory';
			if ($directory) {
				$this->access->assertAppAdmin($user);
			}
			$out = [];
			foreach ($this->userManager->search($q, $limit) as $u) {
				$uid = $u->getUID();
				if (!$directory && !$this->access->canAccessApp($uid)) {
					continue;
				}
				$out[] = [
					'userId' => $uid,
					'displayName' => $u->getDisplayName() ?: $uid,
				];
			}
			return $this->ok(['users' => $out]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function setUnlockQr(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$this->unlock->setQr(
				(string)$this->request->getParam('userId'),
				(string)$this->request->getParam('payload'),
				$user,
			);
			return $this->ok();
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadMyMonthPdf(): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAccess($user);
			$this->assertNotCrossSiteDownload();
			$period = $this->periods->findOpen() ?? $this->periods->findLatestClosed();
			if ($period === null) {
				return $this->fail('period_closed', 409, 'No period available');
			}
			$lineArr = [];
			foreach ($this->logMapper->findForUserPeriod((int)$period->getId(), $user) as $l) {
				if ($l->getBillingBucket() === 'company_hospitality') {
					continue;
				}
				$lineArr[] = [
					'line_total_cents' => (int)$l->getLineTotalCents(),
					'billing_bucket' => 'personal',
					'name' => $l->getItemNameSnap(),
					'qty' => $l->getQty(),
					'free' => ((int)$l->getLineTotalCents()) === 0,
				];
			}
			$calc = $this->subsidy->computeForUser($this->settings->getSubsidyAllowanceCents(), $lineArr);
			$chargeable = [];
			$freeQty = 0;
			foreach ($lineArr as $row) {
				if (!empty($row['free'])) {
					$freeQty += (int)$row['qty'];
					continue;
				}
				$chargeable[] = $row;
			}
			$lines = [
				'Period: ' . $period->getLabel(),
				'User: ' . $user,
				'To deduct: ' . PayrollExportService::centsToEur($calc['deduct_cents']) . ' EUR',
				'Gross: ' . PayrollExportService::centsToEur($calc['gross_cents']) . ' EUR',
				'Subsidy: ' . PayrollExportService::centsToEur($calc['subsidy_cents']) . ' EUR',
				'Free items qty: ' . $freeQty,
				'--- Chargeable ---',
			];
			foreach ($chargeable as $row) {
				$lines[] = $row['name'] . ' x' . $row['qty'] . ' = ' . PayrollExportService::centsToEur((int)$row['line_total_cents']) . ' EUR';
			}
			$pdf = SimplePdfBuilder::fromLines('SnackCheck My month', $lines);
			return new DataDownloadResponse($pdf, 'snackcheck-my-month.pdf', 'application/pdf');
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function userTotals(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$focus = $this->request->getParam('userId');
			$siteIds = null;
			if (!$this->access->isAppAdmin($user)) {
				$siteIds = array_map(static fn ($s) => (int)$s->getId(), $this->access->sitesVisibleTo($user));
			}
			$data = $this->adminTotals->buildForOpenPeriod(
				$focus !== null && $focus !== '' ? (string)$focus : null,
				$siteIds,
			);
			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function userAudit(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$events = [];
			foreach ($this->auditService->recent(100) as $e) {
				$events[] = [
					'createdAt' => $e->getCreatedAt()?->format('c'),
					'actor' => $e->getActorUid(),
					'action' => $e->getAction(),
					'entityType' => $e->getEntityType(),
					'entityId' => $e->getEntityId(),
				];
			}
			return $this->ok(['events' => $events]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	public function searchGroups(): JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$q = trim((string)($this->request->getParam('q') ?? ''));
			$out = [];
			foreach ($this->groupManager->search($q, 50) as $g) {
				$out[] = ['gid' => $g->getGID(), 'displayName' => $g->getDisplayName()];
			}
			return $this->ok(['groups' => $out]);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function brReport(): JSONResponse|DataDownloadResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			// Argus: CSRF-exempt GET — reject cross-site before aggregating payroll data.
			$this->assertNotCrossSiteDownload();
			$periodId = (int)($this->request->getParam('periodId') ?: 0);
			$data = $periodId > 0
				? $this->brAggregate->buildForPeriod($periodId)
				: $this->brAggregate->buildForOpenPeriod();
			$format = (string)($this->request->getParam('format') ?? 'json');
			if ($format === 'csv') {
				$rows = [];
				foreach ($data['byItem'] as $row) {
					$rows[] = [$row['category'], $row['itemName'], $row['qty'], PayrollExportService::centsToEur($row['eurCents'])];
				}
				$csv = CsvExportBuilder::build(['category', 'item_name', 'qty', 'eur'], $rows);
				return new DataDownloadResponse($csv, 'snackcheck-br-aggregate.csv', 'text/csv');
			}
			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complimentaryExport(int $id): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertAppAdmin($user);
			$this->assertNotCrossSiteDownload();
			$csv = $this->complimentary->toCsv($id);
			return new DataDownloadResponse($csv, 'snackcheck-complimentary.csv', 'text/csv');
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shelfQr(int $id): DataDownloadResponse|JSONResponse
	{
		try {
			$user = $this->uid();
			$this->access->assertKitchenManager($user);
			$this->assertNotCrossSiteDownload();
			$item = $this->catalog->get($id);
			$this->access->assertCanManageSite($user, (int)$item->getSiteId());
			$svg = $this->shelfQr->svgForItem($id);
			return new DataDownloadResponse($svg, 'snackcheck-shelf-' . $id . '.svg', 'image/svg+xml');
		} catch (\Throwable $e) {
			return $this->fromDomain($e);
		}
	}

	/** @return list<string> */
	private function csvList(string $raw): array
	{
		$parts = preg_split('/[\s,;]+/', $raw) ?: [];
		$out = [];
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p !== '') {
				$out[] = $p;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Fail closed: directory pickers are UI-only; APIs must reject ghost UIDs.
	 *
	 * @param list<mixed> $uids
	 * @return list<string>
	 */
	private function requireExistingUsers(array $uids): array
	{
		$out = [];
		foreach ($uids as $uid) {
			$uid = trim((string)$uid);
			if ($uid === '') {
				continue;
			}
			$out[] = $this->requireExistingUser($uid);
		}
		return array_values(array_unique($out));
	}

	private function requireExistingUser(string $uid): string
	{
		$uid = trim($uid);
		if ($uid === '' || $this->userManager->get($uid) === null) {
			throw new \OCA\SnackCheck\Exception\DomainException('validation_failed', 'Unknown user', 422);
		}
		return $uid;
	}

	/**
	 * @param list<mixed> $gids
	 * @return list<string>
	 */
	private function requireExistingGroups(array $gids): array
	{
		$out = [];
		foreach ($gids as $gid) {
			$gid = trim((string)$gid);
			if ($gid === '') {
				continue;
			}
			if (!$this->groupManager->groupExists($gid)) {
				throw new \OCA\SnackCheck\Exception\DomainException('validation_failed', 'Unknown group', 422);
			}
			$out[] = $gid;
		}
		return array_values(array_unique($out));
	}

	private function uid(): string
	{
		$u = $this->userSession->getUser();
		if ($u === null) {
			throw new \OCA\SnackCheck\Exception\DomainException('permission_denied', 'Login required', 403);
		}
		return $u->getUID();
	}

	/**
	 * Argus MF: CSRF-exempt GET downloads must reject explicit cross-site fetches
	 * (Sec-Fetch-Site: cross-site). Same-origin / same-site / missing header (legacy
	 * agents, curl) still allowed so admin download links keep working.
	 */
	private function assertNotCrossSiteDownload(): void
	{
		$site = strtolower(trim((string)$this->request->getHeader('Sec-Fetch-Site')));
		if ($site === 'cross-site') {
			throw new \OCA\SnackCheck\Exception\DomainException(
				'permission_denied',
				'Cross-site download blocked',
				403,
			);
		}
	}

	/**
	 * Bachus / Aristoteles: accept euro subsidy from UI; cents remain the SSoT wire format.
	 */
	private function parseEuroToCents(string $raw): int
	{
		$normalized = str_replace([' ', ','], ['', '.'], trim($raw));
		if ($normalized === '' || preg_match('/[eE]/', $normalized) === 1) {
			throw new \OCA\SnackCheck\Exception\DomainException('validation_failed', 'Invalid subsidy amount', 422);
		}
		if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
			throw new \OCA\SnackCheck\Exception\DomainException('validation_failed', 'Invalid subsidy amount', 422);
		}
		$euro = (float)$normalized;
		if ($euro < 0 || $euro > 1_000_000) {
			throw new \OCA\SnackCheck\Exception\DomainException('validation_failed', 'Invalid subsidy amount', 422);
		}
		$cents = (int)round($euro * 100);
		if ($cents < 0 || $cents > 100_000_000) {
			throw new \OCA\SnackCheck\Exception\DomainException('validation_failed', 'Invalid subsidy amount', 422);
		}
		return $cents;
	}

	private function truthy(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return ((int)$value) === 1;
		}
		if (!is_string($value)) {
			return false;
		}
		$v = strtolower(trim($value));
		return in_array($v, ['1', 'true', 'yes', 'on'], true);
	}
}
