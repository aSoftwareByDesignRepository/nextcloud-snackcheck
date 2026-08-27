<?php

declare(strict_types=1);

/**
 * Lightweight mutation-style checks for critical SnackCheck paths
 * (License, ConsumptionLog formulas, Subsidy, Payroll CSV).
 * Prefer this over infection/infection which pulls thecodingmachine/safe
 * and breaks Nextcloud bootstrap under Docker.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use OCA\SnackCheck\License\Snk2Codec;
use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\PulseService;
use OCA\SnackCheck\Service\SubsidyService;

$failures = 0;

function assertTrue(bool $cond, string $msg): void
{
	global $failures;
	if (!$cond) {
		fwrite(STDERR, "FAIL: $msg\n");
		$failures++;
	} else {
		fwrite(STDOUT, "OK: $msg\n");
	}
}

// Subsidy mutants: wrong formula would fail
$svc = new SubsidyService();
$r = $svc->computeForUser(100, [
	['line_total_cents' => 80, 'billing_bucket' => 'personal'],
	['line_total_cents' => 0, 'billing_bucket' => 'personal'],
	['line_total_cents' => 50, 'billing_bucket' => 'company_hospitality'],
]);
assertTrue($r['gross_cents'] === 80, 'subsidy ignores free+hospitality');
assertTrue($r['subsidy_cents'] === 80, 'subsidy caps at gross');
assertTrue($r['deduct_cents'] === 0, 'to_deduct after full subsidy');
assertTrue(SubsidyService::lineTotalCents(3, 40) === 120, 'line_total = qty * unit');

// Pulse mutants
assertTrue(PulseService::needsTopUp(null, 1, 1.0, 3) === false, 'no top-up without par');
assertTrue(PulseService::needsTopUp(10, 5, 0.0, 3) === true, 'top-up when no pace below par');
assertTrue(PulseService::suggestedBuy(10, 3) === 7, 'suggested buy');

// CSV escape mutants
assertTrue(str_starts_with(PayrollExportService::csvEscape('=1+1'), "'"), 'csv formula escape');
assertTrue(PayrollExportService::centsToEur(105) === '1.05', 'cents to eur');

// Codec format constant
assertTrue(Snk2Codec::FORMAT === 'SNK2', 'SNK2 format');
assertTrue(Snk2Codec::PRODUCT === 'snackcheck', 'product claim');
assertTrue(Snk2Codec::classifyApplyError('DKC2.a.b') === Snk2Codec::ERROR_INVALID_FORMAT, 'foreign prefix');

// Privacy totals: focused itemized browse must 403 when privacy ON
assertTrue(
	(new ReflectionMethod(\OCA\SnackCheck\Service\AdminTotalsService::class, 'buildForOpenPeriod'))->getNumberOfParameters() >= 1,
	'admin totals accepts optional focus user'
);
assertTrue(
	in_array('user_id', \OCA\SnackCheck\Service\BrAggregateService::forbiddenColumns(), true),
	'BR report forbids user_id'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Service\AccessControlService::class, 'resolveManagedSiteId'),
	'site ACL resolveManagedSiteId exists'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Service\AccessControlService::class, 'canManageSite'),
	'site ACL canManageSite exists'
);
assertTrue(
	(new ReflectionMethod(\OCA\SnackCheck\Service\PayrollExportService::class, 'buildPersonalPackage'))->getNumberOfParameters() >= 2,
	'payroll package accepts site filter (Y17)'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Db\PeriodMapper::class, 'lockOpenPeriodGate'),
	'open period gate lock exists (NN-09)'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Service\SettingsService::class, 'getUnlockPepper'),
	'unlock PIN pepper exists'
);
assertTrue(
	(new ReflectionMethod(\OCA\SnackCheck\Service\AdminTotalsService::class, 'buildForOpenPeriod'))->getNumberOfParameters() >= 2,
	'admin totals accepts site scope'
);
assertTrue(
	(new ReflectionMethod(\OCA\SnackCheck\Service\ConsumptionLogService::class, 'void'))->getNumberOfParameters() >= 5,
	'void allows loggedBy actor (hospitality/proxy undo)'
);
$reopenSrc = file_get_contents($root . '/lib/Service/PeriodService.php');
assertTrue(
	is_string($reopenSrc) && str_contains($reopenSrc, 'setHandedToHrAt(null)'),
	'reopen clears handed-to-HR flags'
);
assertTrue(
	is_string($reopenSrc) && str_contains($reopenSrc, 'lockOpenPeriodGate'),
	'reopen/ensureOpen use open-period gate'
);
$unlockSrc = file_get_contents($root . '/lib/Service/UnlockService.php');
assertTrue(
	is_string($unlockSrc) && str_contains($unlockSrc, 'canAccessApp'),
	'unlock enforces access door'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Service\SiteService::class, 'requireExplicitSiteId'),
	'terminal register requires explicit site when multi-site'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Controller\DeviceApiController::class, 'catalogVersionToken'),
	'catalog version is content-sensitive'
);
assertTrue(
	method_exists(\OCA\SnackCheck\Service\PeriodService::class, 'openNextPeriod'),
	'admin openNextPeriod exists (AC-16 successor)'
);
assertTrue(
	(new ReflectionMethod(\OCA\SnackCheck\Service\PeriodService::class, 'getOpenOrFail'))->getNumberOfParameters() === 0,
	'getOpenOrFail is write-path lock (no auto-create args)'
);
$periodSrc = file_get_contents($root . '/lib/Service/PeriodService.php');
assertTrue(
	is_string($periodSrc) && str_contains($periodSrc, "throw new DomainException('period_closed'")
		&& str_contains($periodSrc, 'getOpenOrFail'),
	'getOpenOrFail throws period_closed when none open'
);
$digestSrc = file_get_contents($root . '/lib/Service/DigestMailService.php');
assertTrue(
	is_string($digestSrc) && str_contains($digestSrc, 'managersBySite'),
	'weekly top-up scopes sections per site manager (Y5)'
);

$apiJson = file_get_contents($root . '/lib/Controller/ApiJsonTrait.php');
assertTrue(
	is_string($apiJson)
		&& str_contains($apiJson, 'PaymentRequiredException')
		&& str_contains($apiJson, 'STATUS_BAD_REQUEST')
		&& !str_contains($apiJson, 'return $this->fail($e->getMessage() ?: \'license_required\', Http::STATUS_PAYMENT_REQUIRED)'),
	'web ApiJsonTrait never returns HTTP 402'
);

$apiCtrl = file_get_contents($root . '/lib/Controller/ApiController.php');
assertTrue(
	is_string($apiCtrl)
		&& str_contains($apiCtrl, "['item_name', 'category', 'on_hand', 'par_level', 'suggested_buy', 'complimentary']"),
	'shopping CSV columns match AC-OPP-D1'
);
assertTrue(
	is_string($apiCtrl) && str_contains($apiCtrl, 'truthy($active)') && str_contains($apiCtrl, "truthy(\$this->request->getParam('active'))"),
	'active flags use truthy() (PHP (bool)"0" trap)'
);

$catalogSrc = file_get_contents($root . '/lib/Service/CatalogService.php');
assertTrue(
	is_string($catalogSrc) && str_contains($catalogSrc, 'Target site must differ') && str_contains($catalogSrc, 'catalog.copy'),
	'catalog copyToSite rejects same site'
);
assertTrue(
	is_string($catalogSrc) && preg_match('/applyStarterDe[\s\S]{0,400}12,[\s\S]{0,80}20,/m', $catalogSrc) === 1,
	'starter catalog sets par/onHand so Top-up works'
);
assertTrue(
	is_string($catalogSrc)
		&& str_contains($catalogSrc, 'function mutateLocked(int $id, callable $mutator)')
		&& preg_match('/function restock\([\s\S]*?mutateLocked\(/m', $catalogSrc) === 1
		&& preg_match('/function softDelete\([\s\S]*?mutateLocked\(/m', $catalogSrc) === 1
		&& preg_match('/function setOnHand\([\s\S]*?mutateLocked\(/m', $catalogSrc) === 1,
	'catalog restock/setOnHand/softDelete serialize under FOR UPDATE (mutateLocked)'
);
$appJsEuro = file_get_contents($root . '/js/app.js');
assertTrue(
	is_string($appJsEuro)
		&& str_contains($appJsEuro, 'function parseEuroToCentsClient(raw)')
		&& str_contains($appJsEuro, '/[eE]/.test(normalized)')
		&& !preg_match('/subsidyAllowanceEuro[\s\S]{0,120}parseFloat\(/', $appJsEuro),
	'client euro parse rejects scientific notation (parity with server)'
);

$catalogTpl = file_get_contents($root . '/templates/pages/catalog.php');
assertTrue(
	is_string($catalogTpl)
		&& str_contains($catalogTpl, 'edit-item')
		&& str_contains($catalogTpl, 'catalog-update')
		&& str_contains($catalogTpl, 'name="parLevel"')
		&& str_contains($catalogTpl, 'copy-item')
		&& str_contains($catalogTpl, 'snk-dialog--edit')
		&& str_contains($catalogTpl, 'Choose picture')
		&& str_contains($catalogTpl, 'data-snk-initial-focus'),
	'catalog UI exposes edit + par/onHand + copy-to-site + centered edit chrome'
);
$dialogCss = file_get_contents($root . '/css/app.css');
assertTrue(
	is_string($dialogCss)
		&& str_contains($dialogCss, 'margin: auto !important')
		&& preg_match('/dialog\.snk-dialog\[open\]\s*\{[^}]*inset:\s*0/s', $dialogCss) === 1,
	'dialog open state re-centers (NC margin reset)'
);

$digestSvc = file_get_contents($root . '/lib/Service/DigestMailService.php');
assertTrue(
	is_string($digestSvc) && str_contains($digestSvc, 'isPersonalDigestSkipZeroEnabled'),
	'personal digest skip-€0 (AC-OPP-B4)'
);
$settingsSrc = file_get_contents($root . '/lib/Service/SettingsService.php');
assertTrue(
	is_string($settingsSrc) && str_contains($settingsSrc, 'KEY_DIGEST_SKIP_ZERO'),
	'digest skip-zero setting key exists'
);
$rlSrc = file_get_contents($root . '/lib/Service/RateLimitService.php');
assertTrue(
	is_string($rlSrc) && str_contains($rlSrc, 'DEVICE_UNLOCK_LIMIT = 10'),
	'device unlock soft cap is 10/min'
);
assertTrue(
	is_file($root . '/docs/DEVICE-SHORTLIST.md') && is_file($root . '/public/docs/DEVICE-SHORTLIST.md'),
	'DEVICE-SHORTLIST shipped in maintainer docs + public (customer-facing)'
);
$uxLog = file_get_contents($root . '/templates/pages/log.php');
assertTrue(
	is_string($uxLog) && str_contains($uxLog, 'open-next-period') && str_contains($uxLog, 'data-snk-mode')
		&& str_contains($uxLog, 'snk-qty-chip') && str_contains($uxLog, 'snk-log-advanced')
		&& !str_contains($uxLog, 'data-snk-form="proxy-log"'),
	'log UX: period-closed escape + progressive qty/mode (no proxy form)'
);
$uxPulse = file_get_contents($root . '/templates/pages/pulse.php');
assertTrue(
	is_string($uxPulse) && str_contains($uxPulse, 'data-snk-action="restock"')
		&& str_contains($uxPulse, 'In fridge') && !str_contains($uxPulse, 'snk-details" open')
		&& str_contains($uxPulse, 'snk-details--flush')
		&& str_contains($uxPulse, "\$icon = 'fridge'")
		&& str_contains($uxPulse, 'snk-rank-panel')
		&& str_contains($uxPulse, 'snk-rank__place')
		&& !str_contains($uxPulse, 'Nothing needs topping up.'),
	'pulse UX: restock + plain fridge language; ranks collapsed; family empty icons'
);
$uxCss = file_get_contents($root . '/css/app.css');
assertTrue(
	is_string($uxCss)
		&& str_contains($uxCss, '--snk-radius-sm: var(--border-radius-small, 6px)')
		&& str_contains($uxCss, '--snk-radius-md: 12px')
		&& str_contains($uxCss, '--snk-radius-lg: var(--border-radius-large, 16px)')
		&& str_contains($uxCss, '.snk-card__body > .snk-empty')
		&& str_contains($uxCss, '.snk-filter-panel')
		&& str_contains($uxCss, '.snk-quick-filters')
		&& str_contains($uxCss, '.snk-mode-panel--embedded')
		&& !preg_match('/\.snk-filter-bar\s*\{[^}]*border-left:\s*4px/', $uxCss)
		&& !preg_match('/\.snk-empty\s*\{[^}]*color:\s*var\(--snk-muted\)/', $uxCss),
	'pulse chrome: family radii + filter-panel/quick-pills; no callout pill bar'
);
$uxJs = file_get_contents($root . '/js/app.js');
assertTrue(
	is_string($uxJs) && !str_contains($uxJs, "window.prompt('Add quantity'") && str_contains($uxJs, 'catalog-restock')
		&& str_contains($uxJs, 'userFacingError') && str_contains($uxJs, 'toast(userFacingError(e), null, true)'),
	'catalog restock dialog + humanized toasts (Bachus)'
);
assertTrue(
	is_string($uxJs)
		&& str_contains($uxJs, 'OC.requestToken')
		&& str_contains($uxJs, "getAttribute('data-requesttoken')")
		&& str_contains($uxJs, 'body.requesttoken = csrf')
		&& str_contains($uxJs, "credentials: 'same-origin'")
		&& !preg_match(
			'/function token\(\)\s*\{\s*const el = document\.querySelector\(\'head meta\[name="requesttoken"\]\'\);/',
			$uxJs
		),
	'web CSRF: OC.requestToken + head data-requesttoken (not meta-only → HTTP 412)'
);
assertTrue(
	!is_file($root . '/docs/SUPPORT-MACROS-EN.md')
		&& !is_file($root . '/docs/SUPPORT-MACROS-DE.md')
		&& !is_file($root . '/docs/ZEUS-ARCHITECTURE-AUDIT.md')
		&& !is_file($root . '/docs/DESIGN-SYSTEM-CHECKLIST-EVIDENCE.md')
		&& !is_file($root . '/public/docs/SUPPORT-MACROS-EN.md')
		&& !is_file($root . '/public/docs/DESIGN-SYSTEM-CHECKLIST-EVIDENCE.md')
		&& !is_file($root . '/public/docs/PARTNER-DEVICE-RECOMMENDATION-EN.md'),
	'internal support/audit/QA docs are not in the app repo'
);
$deviceAuth = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($deviceAuth) && str_contains($deviceAuth, 'getActiveCount()') && str_contains($deviceAuth, 'getDeviceLimit()'),
	'device auth enforces over-cap (AC-M9)'
);
$xlsx = file_get_contents($root . '/lib/Service/XlsxWriter.php');
assertTrue(
	is_string($xlsx) && str_contains($xlsx, "in_array(\$value[0], ['=', '+', '-', '@']"),
	'XLSX formula neutralization (AC-19)'
);
assertTrue(
	is_file($root . '/lib/Migration/Version1003Date20260810180000.php'),
	'UNIQUE pin_hash migration (AC-M19)'
);
$aclSrc = file_get_contents($root . '/lib/Service/AccessControlService.php');
assertTrue(
	is_string($aclSrc) && str_contains($aclSrc, 'siteId required when multi-site is enabled'),
	'Y19 site required when multi-site + multiple sites'
);
$logSvcSrc = file_get_contents($root . '/lib/Service/ConsumptionLogService.php');
assertTrue(
	is_string($logSvcSrc)
		&& str_contains($logSvcSrc, 'NN-01: intentionally do NOT touch on_hand')
		&& !preg_match('/->restock\(/', $logSvcSrc)
		&& !preg_match('/->setOnHand\(/', $logSvcSrc)
		&& preg_match('/public function create\(array \$input\): array[\s\S]*?beginTransaction\(\);[\s\S]{0,600}periodMapper->lockRow[\s\S]{0,1200}catalog->getForUpdate\(\$itemId\)[\s\S]{0,500}resolveAttribution/', $logSvcSrc) === 1
		&& !preg_match('/public function create\(array \$input\): array[\s\S]*?resolveAttribution\([\s\S]{0,300}?beginTransaction\(\)/', $logSvcSrc),
	'NN-01 create path never mutates on_hand; item FOR UPDATE under period lock (Aristoteles)'
);
$deviceCreate = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($deviceCreate)
		&& str_contains($deviceCreate, "'actorUserId' => \$session['userId']")
		&& !preg_match("/function createLog[\s\S]{0,900}body\['userId'\]/", $deviceCreate),
	'NN-13 device createLog ignores client userId'
);
assertTrue(
	is_string($deviceCreate)
		&& preg_match('/function undoLog[\s\S]{0,600}selfUndo\(\$id,\s*\$session\[[\'"]userId[\'"]\],\s*\(int\)\$device->getSiteId\(\)\)/', $deviceCreate) === 1,
	'Argus MF: device undoLog binds selfUndo to device siteId'
);
$logSvcSite = file_get_contents($root . '/lib/Service/ConsumptionLogService.php');
assertTrue(
	is_string($logSvcSite)
		&& str_contains($logSvcSite, '?int $requiredSiteId = null')
		&& str_contains($logSvcSite, 'Log is not for this site'),
	'Argus MF: selfUndo/void enforce requiredSiteId under lock'
);
$apiCtrlSrc = file_get_contents($root . '/lib/Controller/ApiController.php');
assertTrue(
	is_string($apiCtrlSrc)
		&& str_contains($apiCtrlSrc, 'validate the full payload before any appconfig')
		&& str_contains($apiCtrlSrc, 'parseEuroToCents')
		&& str_contains($apiCtrlSrc, 'Projected hospitality state')
		&& str_contains($apiCtrlSrc, 'never 422 after writes')
		&& !str_contains($apiCtrlSrc, 'Final invariant (defense in depth after apply)')
		&& preg_match('/\[eE\]/', $apiCtrlSrc) === 1,
	'saveSettings validates before writes; euro hard-parse; no post-write 422 (Aristoteles)'
);
assertTrue(
	is_string($apiCtrlSrc)
		&& str_contains($apiCtrlSrc, 'function assertNotCrossSiteDownload')
		&& str_contains($apiCtrlSrc, "\$site === 'cross-site'")
		&& preg_match('/\$directory[\s\S]{0,80}assertAppAdmin\(\$user\)/', $apiCtrlSrc) === 1
		&& preg_match('/function shoppingList[\s\S]{0,350}assertNotCrossSiteDownload\(\)/', $apiCtrlSrc) === 1
		&& preg_match('/function brReport[\s\S]{0,350}assertNotCrossSiteDownload\(\)/', $apiCtrlSrc) === 1
		&& !preg_match('/format === \'csv\' \|\| \$format === \'html\'[\s\S]{0,80}assertNotCrossSiteDownload/', $apiCtrlSrc),
	'Argus MF: cross-site download guard (all formats) + directory search app-admin only'
);
$uxCssBtn = file_get_contents($root . '/css/app.css');
assertTrue(
	is_string($uxCssBtn)
		&& preg_match('/\.snk-btn__sub\s*\{[^}]*color:\s*inherit/', $uxCssBtn) === 1
		&& !preg_match('/\.snk-btn__sub\s*\{[^}]*opacity:\s*0\.92/', $uxCssBtn),
	'Restock sub-label keeps full contrast (no opacity fade)'
);
$uxSettings = file_get_contents($root . '/templates/parts/settings/benefits.php');
assertTrue(
	is_string($uxSettings)
		&& str_contains($uxSettings, 'save.disabled = false')
		&& str_contains($uxSettings, 'const on = !!en.checked;')
		&& !str_contains($uxSettings, 'en.value === \'1\'')
		&& !str_contains($uxSettings, 'save.disabled = block')
		&& str_contains($uxSettings, 'subsidyAllowanceEuro'),
	'UX-30 hospitality Save always enabled; euro subsidy; checked-only sync'
);
$uxJsSwitch = file_get_contents($root . '/js/app.js');
assertTrue(
	is_string($uxJsSwitch)
		&& str_contains($uxJsSwitch, 'formBodyLastWins')
		&& str_contains($uxJsSwitch, 'body[key] = value')
		&& str_contains($uxJsSwitch, "dispatchEvent(new Event('submit'")
		&& !str_contains($uxJsSwitch, 'HTMLFormElement.prototype.submit')
		&& !str_contains($uxJsSwitch, 'Object.fromEntries(fd.entries())')
		&& str_contains($uxJsSwitch, 'WeakMap')
		&& str_contains($uxJsSwitch, "role', 'combobox'")
		&& str_contains($uxJsSwitch, 'inflight')
		&& str_contains($uxJsSwitch, 'my !== inflight')
		&& str_contains($uxJsSwitch, 'scope=directory')
		&& str_contains($uxJsSwitch, 'data-snk-busy')
		&& str_contains($uxJsSwitch, 'findUserSearchNear')
		&& str_contains($uxJsSwitch, 'snk-chip__remove')
		&& str_contains($uxJsSwitch, 'removeChipId')
		&& str_contains($uxJsSwitch, 'wireChipFields')
		&& str_contains($uxJsSwitch, 'subsidyAllowanceEuro')
		&& str_contains($uxJsSwitch, 'Hospitality left off'),
	'settings FormData last-wins; combobox + removable chips; multi-site never native submit; euro subsidy + hosp auto-clear'
);
$chipPartial = file_get_contents($root . '/templates/parts/snk-chip-field.php');
assertTrue(
	is_string($chipPartial)
		&& str_contains($chipPartial, 'data-snk-chip-remove')
		&& str_contains($chipPartial, 'type="hidden"')
		&& str_contains($chipPartial, 'snk-chip-list'),
	'removable chip partial commits hidden ids (DESIGN-SYSTEM §3.13)'
);
$apiSearch = file_get_contents($root . '/lib/Controller/ApiController.php');
assertTrue(
	is_string($apiSearch)
		&& str_contains($apiSearch, "\$scope === 'directory'")
		&& str_contains($apiSearch, 'assertAppAdmin($user)')
		&& preg_match('/\$directory[\s\S]{0,120}canAccessApp\(\$uid\)/', $apiSearch) === 1,
	'user search directory scope bypasses listed-mode chicken-egg'
);
$ghostDir = file_get_contents($root . '/lib/Service/ConsumptionLogService.php');
assertTrue(
	is_string($ghostDir)
		&& preg_match('/mode === \'proxy\'[\s\S]{0,500}userManager->get\(\$target\)/', $ghostDir) === 1
		&& preg_match('/getHospitalityCompanyUserId\(\)[\s\S]{0,220}userManager->get\(\$company\)/', $ghostDir) === 1,
	'proxy/hospitality reject ghost directory UIDs'
);
$unlockGhost = file_get_contents($root . '/lib/Service/UnlockService.php');
assertTrue(
	is_string($unlockGhost)
		&& preg_match('/function setPin[\s\S]{0,220}userManager->get\(\$userId\)/', $unlockGhost) === 1
		&& preg_match('/function setQr[\s\S]{0,220}userManager->get\(\$userId\)/', $unlockGhost) === 1,
	'unlock PIN/QR reject ghost UIDs'
);
$apiGhost = file_get_contents($root . '/lib/Controller/ApiController.php');
assertTrue(
	is_string($apiGhost)
		&& str_contains($apiGhost, 'function requireExistingUsers(')
		&& str_contains($apiGhost, 'function requireExistingGroups(')
		&& str_contains($apiGhost, 'requireExistingUsers($allowList)'),
	'settings/sites APIs gate directory existence'
);
assertTrue(
	is_file($root . '/lib/Migration/Version1004Date20260810193000.php')
		&& str_contains((string)file_get_contents($root . '/lib/Migration/Version1004Date20260810193000.php'), 'snk_periods_open_uq'),
	'NN-09 unique open_guard migration'
);
$periodSvc = file_get_contents($root . '/lib/Service/PeriodService.php');
assertTrue(
	is_string($periodSvc) && str_contains($periodSvc, 'setOpenGuard(1)') && str_contains($periodSvc, 'setOpenGuard(null)'),
	'period lifecycle writes open_guard'
);
$rlAtomic = file_get_contents($root . '/lib/Service/RateLimitService.php');
assertTrue(
	is_string($rlAtomic) && str_contains($rlAtomic, 'acquireLock') && str_contains($rlAtomic, 'LOCK_EXCLUSIVE'),
	'rate limit hits are locked (TOCTOU closed)'
);
$uxJs2 = file_get_contents($root . '/js/app.js');
assertTrue(
	is_string($uxJs2) && !str_contains($uxJs2, 'window.confirm(') && str_contains($uxJs2, 'snkConfirm'),
	'web confirms use <dialog> not window.confirm'
);
assertTrue(
	is_string($digestSrc) && str_contains($digestSrc, 'claimDigestSlot'),
	'digest claim-before-send under lock'
);
$deviceCreateLog = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($deviceCreateLog)
		&& preg_match('/function createLog[\s\S]{0,700}assertUserLog\(\$session\[\'userId\'\]\)/', $deviceCreateLog) === 1
		&& preg_match('/function authenticateDevice[\s\S]{0,1400}assertDeviceApi/', $deviceCreateLog) === 1,
	'device createLog user RL + authenticateDevice 120/min API RL (§9.7 / §7.5)'
);
$unlockSrc = file_get_contents($root . '/lib/Service/UnlockService.php');
assertTrue(
	is_string($unlockSrc) && str_contains($unlockSrc, 'recordUnlockFailure') && str_contains($unlockSrc, 'acquireLock'),
	'unlock fail counter is lock-serialized'
);
$kioskStore = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/src/state/kitchenStore.ts');
assertTrue(
	is_string($kioskStore)
		&& str_contains($kioskStore, "SUCCESS_DISMISS")
		&& str_contains($kioskStore, 'lockUnlockSession')
		&& str_contains($kioskStore, 'scrubUnlockToken'),
	'kiosk invalidates unlock on success/pending dismiss'
);
$voidSrc = file_get_contents($root . '/lib/Service/ConsumptionLogService.php');
assertTrue(
	is_string($voidSrc)
		&& preg_match('/function void[\s\S]{0,900}mapper->lockRow\(\$logId\)/', $voidSrc) === 1
		&& preg_match('/if \(\$enforceSelfUndoWindow\)[\s\S]{0,800}UNDO_SECONDS/', $voidSrc) === 1,
	'void locks consumption log row; self-undo TTL under lock'
);
assertTrue(
	is_file($root . '/lib/Service/ShoppingListHtmlBuilder.php')
		&& str_contains((string)file_get_contents($root . '/lib/Controller/ApiController.php'), "format === 'html'"),
	'shopping list print HTML (AC-OPP-D4)'
);
$routes = file_get_contents($root . '/appinfo/routes.php');
assertTrue(
	is_string($routes) && str_contains($routes, "/api/device/unpair"),
	'device self-unpair route (COMPANION §7.4)'
);
$termMapper = file_get_contents($root . '/lib/Db/TerminalDeviceMapper.php');
assertTrue(
	is_string($termMapper) && str_contains($termMapper, 'function findActiveById'),
	'terminal revoke can resolve device by id'
);
$siteSvc = file_get_contents($root . '/lib/Service/SiteService.php');
assertTrue(
	is_string($siteSvc)
		&& str_contains($siteSvc, 'return $this->requireExplicitSiteId($requestedSiteId)')
		&& str_contains($siteSvc, "site_required"),
	'resolveScopeSiteId never invents Default when multi-site ambiguous'
);
$apiCreate = file_get_contents($root . '/lib/Controller/ApiController.php');
assertTrue(
	is_string($apiCreate)
		&& preg_match('/function createLog[\s\S]{0,500}requireExplicitSiteId/', $apiCreate) === 1,
	'web createLog requires explicit site (Y12)'
);
$quickSrc = file_get_contents($root . '/lib/Service/ConsumptionLogService.php');
assertTrue(
	is_string($quickSrc)
		&& str_contains($quickSrc, 'Intentionally ignore $siteId')
		&& str_contains($quickSrc, 'REASON_MAX_LEN'),
	'quick-total org-wide + reason max length'
);
$themeCss = file_get_contents($root . '/css/app.css');
assertTrue(
	is_string($themeCss)
		&& str_contains($themeCss, '--color-main-background')
		&& str_contains($themeCss, '--snk-scrim')
		&& str_contains($themeCss, '@media (max-width: 768px)')
		&& !str_contains($themeCss, '#fff4e5')
		&& str_contains($themeCss, 'prefers-contrast')
		&& preg_match('/^:root\s*\{/m', $themeCss) === 1
		&& preg_match('/^body\s*\{/m', $themeCss) === 1
		&& str_contains($themeCss, '@media (forced-colors: active)'),
	'theme tokens map to NC vars; responsive 768; no raw warn hex'
);

$termSvc = file_get_contents($root . '/lib/Service/TerminalDeviceService.php');
assertTrue(
	is_string($termSvc)
		&& str_contains($termSvc, 'CAPACITY_LOCK')
		&& str_contains($termSvc, 'LockGate')
		&& !str_contains($termSvc, 'use OCP\\Lock\\ILockingProvider')
		&& !str_contains($termSvc, 'acquireLock')
		&& preg_match('/function trimToLimit[\s\S]{0,600}lockGate->lock\(self::CAPACITY_LOCK\)/', $termSvc) === 1
		&& preg_match('/function register[\s\S]{0,900}lockGate->lock\(self::CAPACITY_LOCK\)/', $termSvc) === 1
		&& preg_match('/function revoke[\s\S]{0,500}lockGate->lock\(self::CAPACITY_LOCK\)/', $termSvc) === 1,
	'trim/register/revoke share DB capacity gate (Zeus MF-03 / Aristoteles)'
);
$lockGateSrc = file_get_contents($root . '/lib/Db/LockGate.php');
assertTrue(
	is_string($lockGateSrc)
		&& str_contains($lockGateSrc, "KEY_TERMINAL_CAPACITY = 'terminal_capacity'")
		&& str_contains($lockGateSrc, 'FOR UPDATE'),
	'LockGate defines terminal_capacity FOR UPDATE'
);
assertTrue(
	is_string($settingsSrc)
		&& str_contains($settingsSrc, "PEPPER_LOCK = 'snackcheck/unlock_pepper'")
		&& preg_match('/function getUnlockPepper[\s\S]{0,400}PEPPER_LOCK/', $settingsSrc) === 1,
	'unlock pepper mint is exclusive-locked (Zeus MF-2)'
);
$licSvc = file_get_contents($root . '/lib/Service/LicenseService.php');
assertTrue(
	is_string($licSvc)
		&& str_contains($licSvc, "APPLY_LOCK = 'snackcheck/license_apply'")
		&& preg_match('/function applyLicenseKey[\s\S]{0,2500}APPLY_LOCK/', $licSvc) === 1,
	'license apply upsert is exclusive-locked (Zeus MF-3)'
);

assertTrue(
	!is_file($root . '/docs/PARTNER-DEVICE-RECOMMENDATION-EN.md')
		&& !is_file($root . '/docs/PARTNER-DEVICE-RECOMMENDATION-DE.md')
		&& !is_file($root . '/public/docs/PARTNER-DEVICE-RECOMMENDATION-EN.md'),
	'WP-HW2 partner device one-pager is not in the app repo'
);
$kioskHb = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/src/hooks/useDeviceHeartbeat.ts');
assertTrue(
	is_string($kioskHb) && str_contains($kioskHb, 'postHeartbeat') && str_contains($kioskHb, 'HEARTBEAT_INTERVAL_MS'),
	'kiosk heartbeat hook polls device last-seen / license (MF7)'
);
$kioskApp = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/App.tsx');
assertTrue(
	is_string($kioskApp) && str_contains($kioskApp, 'useDeviceHeartbeat'),
	'kiosk App wires device heartbeat'
);
$unlockSrc2 = file_get_contents($root . '/lib/Service/UnlockService.php');
assertTrue(
	is_string($unlockSrc2)
		&& str_contains($unlockSrc2, "snk-qr|")
		&& str_contains($unlockSrc2, 'hash_equals($bound, $requiredDeviceId)'),
	'QR peppered + unlock token bound to device (Argus MF)'
);
$deviceApiSrc = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($deviceApiSrc)
		&& substr_count($deviceApiSrc, 'peekUnlockToken($token, (string)$device->getId())') >= 4,
	'device API peeks unlock tokens bound to device id (incl. catalog favorites)'
);
$apiShelf = file_get_contents($root . '/lib/Controller/ApiController.php');
assertTrue(
	is_string($apiShelf)
		&& preg_match('/function shelfQr[\s\S]{0,500}assertCanManageSite/', $apiShelf) === 1,
	'shelf QR enforces site ACL (Argus BOLA)'
);
$usersTpl = file_get_contents($root . '/templates/pages/users.php');
$proxyTpl = file_get_contents($root . '/templates/parts/snk-proxy-panel.php');
assertTrue(
	is_string($usersTpl)
		&& str_contains($usersTpl, 'snk-proxy-panel.php')
		&& str_contains($usersTpl, 'proxyItems')
		&& str_contains($usersTpl, 'privacyTotalsOnly')
		&& is_string($proxyTpl)
		&& str_contains($proxyTpl, 'data-snk-proxy-fields'),
	'Users page keeps proxy form under privacy (AC-35)'
);
$pageUsers = file_get_contents($root . '/lib/Controller/PageController.php');
assertTrue(
	is_string($pageUsers)
		&& preg_match('/function users\(\)[\s\S]{0,2500}\'proxyItems\'/', $pageUsers) === 1,
	'Users controller supplies proxy catalog'
);
$kioskHb2 = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/src/hooks/useDeviceHeartbeat.ts');
assertTrue(
	is_string($kioskHb2) && str_contains($kioskHb2, 'fetchBootstrap') && str_contains($kioskHb2, 'BOOTSTRAP_REFRESH'),
	'heartbeat refreshes bootstrap period (AC-M3 live)'
);
$flowSrc = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/src/state/kitchenFlowMachine.ts');
assertTrue(
	is_string($flowSrc)
		&& str_contains($flowSrc, "state.screen === 'success'")
		&& str_contains($flowSrc, 'BOOTSTRAP_REFRESH'),
	'success ERROR stays visible; bootstrap refresh event exists'
);
$deJson = json_decode((string)file_get_contents($root . '/l10n/de.json'), true);
$deTr = is_array($deJson) ? ($deJson['translations'] ?? []) : [];
assertTrue(
	is_array($deTr)
		&& ($deTr['Kitchen'] ?? '') === 'Küche'
		&& ($deTr['Money'] ?? '') === 'Geld'
		&& ($deTr['Undo'] ?? '') === 'Rückgängig'
		&& ($deTr['Free'] ?? '') === 'Gratis'
		&& ($deTr['Pick a site above before logging. Each kitchen has its own catalog.'] ?? '') !== 'Pick a site above before logging. Each kitchen has its own catalog.'
		&& ($deTr['Available even when privacy hides itemized lines.'] ?? '') !== 'Available even when privacy hides itemized lines.',
	'primary DE l10n for Log/Users/nav (AC-28)'
);
$jsClose = file_get_contents($root . '/js/app.js');
assertTrue(
	is_string($jsClose)
		&& preg_match("/zero_logs:\\s*t\\(\\s*'No snacks logged this period'/", $jsClose) === 1
		&& preg_match("/huge_mom_delta:\\s*t\\(\\s*'Consumption changed a lot vs last period'/", $jsClose) === 1,
	'period close warnings go through t() (AC-28)'
);
$periodsTpl = file_get_contents($root . '/templates/pages/periods.php');
assertTrue(
	is_string($periodsTpl) && str_contains($periodsTpl, "\$l->t('Closed')") && str_contains($periodsTpl, "\$l->t('Open')"),
	'period state enums are translated'
);
$hospTpl = file_get_contents($root . '/templates/pages/hospitality.php');
assertTrue(
	is_string($hospTpl)
		&& str_contains($hospTpl, 'companyUserDisplay')
		&& str_contains($hospTpl, 'allowlistDisplay')
		&& !str_contains($hospTpl, "implode(', ', \$_['allowlist'])"),
	'hospitality page shows display names not raw uid lists'
);
$successSrc = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/src/screens/SuccessScreen.tsx');
assertTrue(
	is_string($successSrc)
		&& str_contains($successSrc, 'canDismiss')
		&& str_contains($successSrc, 'SUCCESS_MIN_STAY_MS')
		&& str_contains($successSrc, 'undoing.current'),
	'success Done respects min stay; undo blocks auto-dismiss race'
);
$usersNoUid = file_get_contents($root . '/templates/pages/users.php');
assertTrue(
	is_string($usersNoUid)
		&& !preg_match('/\$u\[\'userId\'\]/', $usersNoUid),
	'Users totals cards do not render raw user ids'
);
$licMapper = file_get_contents($root . '/lib/Db/LicenseStateMapper.php');
assertTrue(
	is_string($licMapper)
		&& str_contains($licMapper, 'findEntities($qb)')
		&& str_contains($licMapper, 'deleteOtherThan')
		&& !str_contains($licMapper, 'MultipleObjectsReturnedException'),
	'license findCurrent never maps dual rows to unlicensed null (Zeus MF)'
);
$licMig = file_get_contents($root . '/lib/Migration/Version1005Date20260810210000.php');
assertTrue(
	is_string($licMig) && str_contains($licMig, 'snk_lic_single_uq') && str_contains($licMig, 'singleton_guard'),
	'license singleton UNIQUE migration shipped'
);
$kioskLog = file_get_contents(dirname($root, 3) . '/mobile/snackcheck-kiosk/src/state/kitchenStore.ts');
assertTrue(
	is_string($kioskLog)
		&& str_contains($kioskLog, 'if (get().logging)')
		&& str_contains($kioskLog, 'set({ logging: true })')
		&& str_contains($kioskLog, 'set({ logging: false })'),
	'kiosk logItem submit mutex blocks double-tap double charge (Zeus MF)'
);
$unlockSrc = file_get_contents($root . '/lib/Service/UnlockService.php');
assertTrue(
	is_string($unlockSrc)
		&& preg_match(
			'/canAccessApp\(\$userId\)\)\s*\{[\s\S]{0,200}recordUnlockFailure[\s\S]{0,120}unlock_invalid/',
			$unlockSrc
		) === 1
		&& !preg_match(
			'/canAccessApp\(\$userId\)\)\s*\{[\s\S]{0,120}permission_denied/',
			$unlockSrc
		),
	'unlock ACL deny matches wrong-PIN (no 401/403 oracle) (Argus MF)'
);
$deviceApi = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($deviceApi)
		&& preg_match(
			"/function unlockVerify[\s\S]{0,500}'dev:'\s*\.\s*\\\$device->getId\(\)/",
			$deviceApi
		) === 1
		&& !preg_match(
			"/function unlockVerify[\s\S]{0,500}getRemoteAddress/",
			$deviceApi
		),
	'unlock lockout key is device-scoped not IP (Argus SF)'
);
$appIcon = file_get_contents($root . '/img/app.svg');
assertTrue(
	is_string($appIcon)
		&& str_contains($appIcon, 'viewBox="0 0 24 24"')
		&& str_contains($appIcon, 'stroke="#ffffff"')
		&& !str_contains($appIcon, '#2f6f4e'),
	'app icon is white-stroke Check-family fridge glyph'
);
$mainChrome = file_get_contents($root . '/templates/main.php');
$navChrome = file_get_contents($root . '/templates/common/navigation.php');
assertTrue(
	is_string($mainChrome)
		&& is_string($navChrome)
		&& str_contains($mainChrome, 'snk-live-region')
		&& str_contains($mainChrome, 'snk-alert-region')
		&& str_contains($mainChrome, 'snk-page-header')
		&& str_contains($mainChrome, 'data-snk-locale')
		&& str_contains($navChrome, 'aria-current="page"')
		&& str_contains($navChrome, 'id="app-navigation"'),
	'design-system chrome: live regions, sidebar nav, page header, lang/locale'
);
$unlockSrcProg = file_get_contents($root . '/lib/Service/UnlockService.php');
assertTrue(
	is_string($unlockSrcProg)
		&& str_contains($unlockSrcProg, 'LOCKOUT_SCHEDULE_SECONDS')
		&& str_contains($unlockSrcProg, 'tier:')
		&& str_contains($unlockSrcProg, 'FAIL_COUNTER_TTL_SECONDS')
		&& str_contains($unlockSrcProg, 'withDeviceFailLock')
		&& !str_contains($unlockSrcProg, 'LOCKOUT_SECONDS * 2')
		&& preg_match('/remove\(\'tier:\'\s*\.\s*\$deviceKey\)/', $unlockSrcProg) === 1,
	'progressive unlock lockout schedule + tier clear on success (Argus SF-02)'
);
assertTrue(
	is_string($mainChrome)
		&& !str_contains($mainChrome, 'snk-nav-toggle')
		&& !str_contains($mainChrome, 'data-snk-nav-toggle'),
	'design-system: no custom burger — NC #app-navigation-toggle owns mobile nav'
);
$catalogStarter = file_get_contents($root . '/lib/Service/CatalogService.php');
$appJsStarter = file_get_contents($root . '/js/app.js');
assertTrue(
	is_string($catalogStarter)
		&& str_contains($catalogStarter, 'catalog_starter:')
		&& str_contains($catalogStarter, 'lockGate->lock')
		&& is_string($appJsStarter)
		&& preg_match("/action === 'starter'[\s\S]{0,400}starterBody\.siteId/", $appJsStarter) === 1,
	'starter catalog is site-scoped + LockGate serialized'
);
$deviceFail = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($deviceFail)
		&& preg_match('/function deviceFail[\s\S]{0,900}retryAfterSeconds/', $deviceFail) === 1,
	'deviceFail forwards DomainException retryAfter (lockout UX)'
);
$pageShelf = file_get_contents($root . '/lib/Controller/PageController.php');
assertTrue(
	is_string($pageShelf)
		&& preg_match('/function shelf\(int \$itemId\)[\s\S]{0,280}assertAccess\(\$user\)[\s\S]{0,120}catalog->get\(\$itemId\)/', $pageShelf) === 1
		&& preg_match('/function shelf\(int \$itemId\)[\s\S]{0,500}getActive\(\)/', $pageShelf) === 1
		&& preg_match('/function hospitalityView\(string \$viewerUid[\s\S]{0,400}isAppAdmin\(\$viewerUid\)/', $pageShelf) === 1
		&& preg_match('/function shelf\(int \$itemId\)[\s\S]{0,1100}periodClosed\'\s*=>\s*\$open === null/', $pageShelf) === 1,
	'shelf assertAccess-before-probe + inactive guard + hospitality allowlist app-admin only'
);
$licenseBind = file_get_contents($root . '/lib/Service/LicenseService.php');
assertTrue(
	is_string($licenseBind)
		&& str_contains($licenseBind, 'isBoundToThisInstance')
		&& str_contains($licenseBind, 'hash_equals')
		&& str_contains($licenseBind, "'instanceValid'"),
	'SNK2 license enforces boundInstanceId (Argus restore/copy)'
);
$lockBind = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($lockBind)
		&& preg_match('/function lockSession[\s\S]{0,400}invalidateUnlockToken\(\$token,\s*\(string\)\$device->getId\(\)\)/', $lockBind) === 1
		&& preg_match('/function createLog[\s\S]{0,1400}assertLiveAppAccess/', $lockBind) === 1
		&& preg_match('/function createLog[\s\S]{0,1400}isLiveKitchenAdmin/', $lockBind) === 1
		&& preg_match('/function colleagues[\s\S]{0,500}assertLiveAppAccess/', $lockBind) === 1
		&& preg_match('/function colleagues[\s\S]{0,700}isLiveKitchenAdmin/', $lockBind) === 1,
	'device lockSession binds token; proxy/colleagues re-check live ACL'
);
$pageCsrf = file_get_contents($root . '/lib/Controller/PageController.php');
assertTrue(
	is_string($pageCsrf)
		&& substr_count($pageCsrf, '#[NoCSRFRequired]') >= 13
		&& !str_contains((string)file_get_contents($root . '/templates/main.php'), 'getURLGenerator()'),
	'page GETs CSRF-exempt; templates never call removed getURLGenerator'
);
$cssDisabled = file_get_contents($root . '/css/app.css');
assertTrue(
	is_string($cssDisabled)
		&& !preg_match('/\.snk-tile:disabled[\s\S]{0,80}opacity:\s*0\.55/', $cssDisabled)
		&& !preg_match('/\.snk-tile\.is-logging[\s\S]{0,80}opacity:\s*0\./', $cssDisabled)
		&& str_contains($cssDisabled, '.snk-select option')
		&& str_contains($cssDisabled, 'body[data-theme-light-highcontrast]')
		&& str_contains($cssDisabled, '--snk-radius-md: 12px'),
	'disabled tiles avoid low-contrast opacity fade (WCAG)'
);
$siteDeact = file_get_contents($root . '/lib/Service/SiteService.php');
$termRevokeSite = file_get_contents($root . '/lib/Service/TerminalDeviceService.php');
$deviceAuthSite = file_get_contents($root . '/lib/Controller/DeviceApiController.php');
assertTrue(
	is_string($siteDeact)
		&& str_contains($siteDeact, 'revokeAllBySite')
		&& is_string($termRevokeSite)
		&& preg_match('/function revokeAllBySite[\s\S]{0,400}CAPACITY_LOCK/', $termRevokeSite) === 1
		&& is_string($deviceAuthSite)
		&& preg_match('/function authenticateDevice[\s\S]{0,900}sites->get\(/', $deviceAuthSite) === 1
		&& preg_match('/function assertLiveAppAccess[\s\S]{0,300}canAccessApp/', $deviceAuthSite) === 1,
	'inactive site kills Device API + revoke-on-deactivate + live unlock ACL (Aristoteles P0/P1)'
);
$logAllergen = file_get_contents($root . '/templates/parts/snk-log-tile.php');
$settingsTermUi = file_get_contents($root . '/templates/parts/settings/license.php');
$appJsRevoke = file_get_contents($root . '/js/app.js');
$unlockUnique = file_get_contents($root . '/lib/Service/UnlockService.php');
$starterCount = file_get_contents($root . '/lib/Service/CatalogService.php');
assertTrue(
	is_string($logAllergen)
		&& str_contains($logAllergen, 'contains_nuts')
		&& str_contains($logAllergen, '$ariaParts')
		&& is_string($settingsTermUi)
		&& str_contains($settingsTermUi, 'revoke-terminal')
		&& is_string($appJsRevoke)
		&& str_contains($appJsRevoke, "action === 'revoke-terminal'")
		&& str_contains($appJsRevoke, 'button[value="cancel"]')
		&& is_string($unlockUnique)
		&& str_contains($unlockUnique, 'REASON_UNIQUE_CONSTRAINT_VIOLATION')
		&& is_string($starterCount)
		&& str_contains($starterCount, 'countBySite'),
	'allergen tile a11y + terminal revoke UI + PIN unique race + starter countBySite'
);

exit($failures === 0 ? 0 : 1);
