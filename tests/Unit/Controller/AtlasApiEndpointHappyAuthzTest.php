<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use DG\BypassFinals;
use OCA\SnackCheck\Config\InstanceId;
use OCA\SnackCheck\Controller\ApiController;
use OCA\SnackCheck\Controller\DeviceApiController;
use OCA\SnackCheck\Controller\PageController;
use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\TerminalDevice;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AdminTotalsService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\BrAggregateService;
use OCA\SnackCheck\Service\CatalogImageService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ComplimentaryExportService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\DigestMailService;
use OCA\SnackCheck\Service\LicenseEnforcementService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\MyMonthStatementPresenter;
use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\PulseService;
use OCA\SnackCheck\Service\RateLimitService;
use OCA\SnackCheck\Service\SettingsSectionCatalog;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\ShelfQrService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\SubsidyService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

BypassFinals::enable();

if (!class_exists('OC_Util', false)) {
	eval('class OC_Util { public static function addStyle($a = null, $b = null, $c = false): void {} public static function addScript($a = null, $b = null, $c = null, $d = false): void {} }');
}

if (!class_exists('OC', false)) {
	eval(<<<'PHP'
class OC {
	/** @var object|null */
	public static $server;
}
PHP);
}

if (!class_exists('OC\\AppScriptDependency', false)) {
	eval(<<<'PHP'
namespace OC;
class AppScriptDependency {
	public function __construct($application = null, $deps = []) {}
	public function addDep($afterAppId): void {}
}
class AppScriptSort {
	public function sort($scripts, $deps) { return $scripts; }
}
PHP);
}

if (\OC::$server === null) {
	\OC::$server = new class {
		public function get(string $id): mixed
		{
			if ($id === \OCP\L10N\IFactory::class) {
				$f = new class {
					public function findLanguage($app = null): string
					{
						return 'en';
					}
					public function get($app): object
					{
						return new class {
							public function t(string $s, array $p = []): string
							{
								return $s;
							}
						};
					}
				};
				return $f;
			}
			if ($id === \OC\AppScriptSort::class) {
				return new \OC\AppScriptSort();
			}
			if ($id === \OCP\AppFramework\Utility\ITimeFactory::class
				|| $id === \OCP\IRequest::class
				|| str_contains($id, 'TimeFactory')
			) {
				$t = new class {
					public function getTime(): int
					{
						return time();
					}
					public function getDateTime($time = 'now', $tz = null): \DateTime
					{
						return new \DateTime('2026-08-10T12:00:00+00:00');
					}
				};
				return $t;
			}
			return new \stdClass();
		}
	};
}

/**
 * Atlas v3 — per-endpoint happy (2xx/3xx + ok envelope) and AuthZ deny proofs.
 * Never treats 404 as AuthZ deny; never accepts Response-only / designed-4xx-as-happy theater.
 */
final class AtlasApiEndpointHappyAuthzTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];

	private const CONTROLLERS = [
		PageController::class,
		ApiController::class,
		DeviceApiController::class,
	];

	/** @var array<string, list<string>> */
	private const AUTHZ_ACTIONS = [
		PageController::class => [
			'log', 'myMonth', 'catalog', 'pulse', 'periods', 'hospitality', 'sites',
			'users', 'brReport', 'audit', 'settingsIndex', 'settings', 'shelf',
		],
		ApiController::class => [
			'createLog', 'undoLog', 'voidLog', 'applyStarter', 'createCatalogItem',
			'updateCatalogItem', 'deleteCatalogItem', 'restockItem', 'copyCatalogItem',
			'setOnHand', 'uploadCatalogImage', 'deleteCatalogImage', 'catalogImage', 'shelfQr',
			'openNextPeriod', 'closePeriod', 'reopenPeriod', 'markHandedToHr',
			'downloadPayroll', 'downloadHospitality', 'complimentaryExport',
			'createSite', 'updateSite', 'shoppingList', 'downloadMyMonthPdf',
			'applyLicense', 'clearLicense', 'registerTerminal', 'revokeTerminal',
			'saveSettings', 'setUnlockPin', 'setUnlockQr',
			'userTotals', 'searchUsers', 'userAudit', 'searchGroups', 'brReport',
		],
		DeviceApiController::class => [
			'bootstrap', 'catalog', 'catalogImage', 'unlockVerify', 'lockSession',
			'createLog', 'undoLog', 'colleagues', 'heartbeat', 'unpair',
		],
	];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		BypassFinals::enable();
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionHappyPathIs2xxOrRedirect(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::CONTROLLERS as $class) {
			$this->byType = [];
			$ctrl = $this->buildController($class, allow: true, mode: 'happy');
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
					continue;
				}
				if ($method->isStatic()) {
					// DeviceApiController::catalogVersionToken — prove separately below
					continue;
				}
				$symbol = $ref->getShortName() . '::' . $method->getName();
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				if (!$result instanceof Response) {
					$failures[] = $symbol . ' not Response';
					continue;
				}
				$status = $result->getStatus();
				$okStatus = ($status >= 200 && $status < 300) || ($status >= 300 && $status < 400);
				if (!$okStatus) {
					$body = '';
					if ($result instanceof JSONResponse) {
						$body = (string)json_encode($result->getData());
					}
					$failures[] = $symbol . ' status=' . $status . ' body=' . $body;
					continue;
				}
				if ($status < 300 && $result instanceof JSONResponse) {
					$data = $result->getData();
					if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== true) {
						$failures[] = $symbol . ' ok=false body=' . json_encode($data);
						continue;
					}
				}
				$proved[] = $symbol;
			}
		}

		$token = DeviceApiController::catalogVersionToken([]);
		self::assertIsString($token);
		$proved[] = 'DeviceApiController::catalogVersionToken';

		self::assertSame([], $failures, "Happy-path failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(60, count($proved), 'expected ≥60 controller actions, got ' . count($proved));
		fwrite(STDERR, 'ATLAS_HAPPY proved=' . count($proved) . "\n");
	}

	public function testAuthzNegativePerEndpointAction(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::AUTHZ_ACTIONS as $class => $actions) {
			foreach ($actions as $action) {
				$this->byType = [];
				$ctrl = $this->buildController($class, allow: false, mode: 'authz');
				$ref = new ReflectionClass($class);
				self::assertTrue($ref->hasMethod($action), $class . '::' . $action);
				$method = $ref->getMethod($action);
				$symbol = $ref->getShortName() . '::' . $action;
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
					if (!$result instanceof Response) {
						$failures[] = $symbol . ' not Response';
						continue;
					}
					$status = $result->getStatus();
					if ($status === 404 || $status < 400) {
						$body = $result instanceof JSONResponse ? (string)json_encode($result->getData()) : '';
						$failures[] = $symbol . ' deny status=' . $status . ' body=' . $body;
						continue;
					}
					if ($result instanceof JSONResponse) {
						$data = $result->getData();
						if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== false) {
							$failures[] = $symbol . ' deny envelope ok!=false';
							continue;
						}
					}
				} catch (DomainException $e) {
					if ($e->httpStatus === 404) {
						$failures[] = $symbol . ' threw DomainException 404 (not AuthZ)';
						continue;
					}
					self::assertGreaterThanOrEqual(400, $e->httpStatus, $symbol);
					self::assertNotSame(404, $e->httpStatus, $symbol);
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "AuthZ failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(55, count($proved), 'expected ≥55 authz negatives, got ' . count($proved));
		fwrite(STDERR, 'ATLAS_AUTHZ proved=' . count($proved) . "\n");
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param 'happy'|'authz' $mode
	 * @return T
	 */
	private function buildController(string $class, bool $allow, string $mode): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		self::assertNotNull($ctor);
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();
			if ($name === 'appName') {
				$args[] = 'snackcheck';
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request($mode, $class, $allow);
				continue;
			}
			if ($type === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode, $class);
		}
		if ($class === DeviceApiController::class) {
			$ctrl = new AtlasTestableDeviceApiController(...$args);
			$ctrl->forcedJsonBody = [
				'pin' => '1234',
				'unlockToken' => 'tok',
				'mode' => 'self',
				'itemId' => 1,
				'qty' => 1,
				'idempotencyKey' => 'idem-1',
			];
			return $ctrl;
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null') {
					return $t->getName();
				}
			}
		}
		return null;
	}

	/** @param class-string $controllerClass */
	private function mockFor(string $typeName, bool $allow, string $mode, string $controllerClass): object
	{
		$key = $typeName . ':' . ($allow ? '1' : '0') . ':' . $mode . ':' . $controllerClass;
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}

		if ($typeName === AccessControlService::class) {
			$mock = $this->createMock(AccessControlService::class);
			$deny = static function () use ($allow, $mode): void {
				if (!$allow && $mode === 'authz') {
					throw new DomainException('permission_denied', 'Access denied', 403);
				}
			};
			$mock->method('assertAccess')->willReturnCallback($deny);
			$mock->method('assertAppAdmin')->willReturnCallback($deny);
			$mock->method('assertKitchenManager')->willReturnCallback($deny);
			$mock->method('assertCanManageSite')->willReturnCallback($deny);
			$mock->method('canAccessApp')->willReturn($allow);
			$mock->method('isAppAdmin')->willReturn($allow);
			$mock->method('isKitchenManager')->willReturn($allow);
			$mock->method('canManageSite')->willReturn($allow);
			$mock->method('sitesVisibleTo')->willReturn([$this->site()]);
			$mock->method('resolveManagedSiteId')->willReturnCallback(
				static function () use ($allow, $mode): int {
					if (!$allow && $mode === 'authz') {
						throw new DomainException('permission_denied', 'Access denied', 403);
					}
					return 1;
				}
			);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === IUserSession::class) {
			$mock = $this->createMock(IUserSession::class);
			if ($allow || $mode !== 'authz') {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('alice');
				$user->method('getDisplayName')->willReturn('Alice');
				$mock->method('getUser')->willReturn($user);
			} else {
				// still return user so assert* is the deny path (not login-required)
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('bob');
				$mock->method('getUser')->willReturn($user);
			}
			return $this->byType[$key] = $mock;
		}

		if ($typeName === TerminalDeviceService::class) {
			$mock = $this->createMock(TerminalDeviceService::class);
			if ($allow && $mode === 'happy') {
				$mock->method('resolveToken')->willReturn($this->device());
				$mock->method('getDeviceLimit')->willReturn(10);
				$mock->method('getActiveCount')->willReturn(1);
				$mock->method('listActive')->willReturn([]);
				$mock->method('register')->willReturn(['ok' => true, 'deviceId' => 7, 'token' => 'snkterm_x', 'label' => 'Kitchen']);
				$mock->method('revoke')->willReturn(['ok' => true]);
			} else {
				$mock->method('resolveToken')->willReturnCallback(
					static function (): never {
						throw new DomainException('no_device', 'Device not found', 401);
					}
				);
			}
			return $this->byType[$key] = $mock;
		}

		if ($typeName === LicenseService::class) {
			$mock = $this->createMock(LicenseService::class);
			$mock->method('isTerminalPlanActive')->willReturn(true);
			$mock->method('buildEnvelope')->willReturn(['format' => 'SNK2', 'payloadB64' => 'p', 'signatureB64' => 's']);
			$mock->method('getLicenseSummary')->willReturn(['status' => 'active']);
			$mock->method('hasStoredLicense')->willReturn(true);
			$mock->method('requireTerminalLicense')->willReturnCallback(static function (): void {});
			$mock->method('applyLicenseKey')->willReturn(true);
			$mock->method('getTerminalDeviceLimit')->willReturn(10);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === CatalogService::class) {
			$mock = $this->createMock(CatalogService::class);
			$item = $this->item();
			$mock->method('listActive')->willReturn([$item]);
			$mock->method('listAll')->willReturn([$item]);
			$mock->method('get')->willReturn($item);
			$mock->method('getForUpdate')->willReturn($item);
			$mock->method('create')->willReturn($item);
			$mock->method('update')->willReturn($item);
			$mock->method('restock')->willReturn($item);
			$mock->method('setOnHand')->willReturn($item);
			$mock->method('copyToSite')->willReturn($item);
			$mock->method('applyStarterDe')->willReturn([$item]);
			$mock->method('softDelete')->willReturn($item);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === CatalogImageService::class) {
			$mock = $this->createMock(CatalogImageService::class);
			$mock->method('hasImage')->willReturn(false);
			$mock->method('read')->willReturn(['content' => 'x', 'mime' => 'image/png', 'body' => 'x']);
			$mock->method('upload')->willReturn($this->item());
			return $this->byType[$key] = $mock;
		}

		if ($typeName === PeriodService::class) {
			$mock = $this->createMock(PeriodService::class);
			$p = $this->period();
			$mock->method('findOpen')->willReturn($p);
			$mock->method('findLatestClosed')->willReturn(null);
			$mock->method('ensureOpenPeriod')->willReturn($p);
			$mock->method('getOpenOrFail')->willReturn($p);
			$mock->method('get')->willReturn($p);
			$mock->method('listAll')->willReturn([$p]);
			$mock->method('openNextPeriod')->willReturn($p);
			$mock->method('close')->willReturn(['period' => $p, 'warnings' => []]);
			$mock->method('reopen')->willReturn($p);
			$mock->method('markHandedToHr')->willReturn($p);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === SiteService::class) {
			$mock = $this->createMock(SiteService::class);
			$s = $this->site();
			$mock->method('get')->willReturn($s);
			$mock->method('listActive')->willReturn([$s]);
			$mock->method('listAll')->willReturn([$s]);
			$mock->method('create')->willReturn($s);
			$mock->method('update')->willReturn($s);
			$mock->method('managerUids')->willReturn(['alice']);
			$mock->method('requireExplicitSiteId')->willReturn(1);
			$mock->method('getDefaultSiteId')->willReturn(1);
			$mock->method('resolveScopeSiteId')->willReturn(1);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === ConsumptionLogService::class) {
			$mock = $this->createMock(ConsumptionLogService::class);
			$log = $this->log();
			$mock->method('create')->willReturn(['log' => $log, 'replay' => false, 'httpStatus' => 201]);
			$mock->method('selfUndo')->willReturn($log);
			$mock->method('void')->willReturn($log);
			$mock->method('distinctUserIdsForSite')->willReturn(['alice']);
			$mock->method('quickTotalCentsForUser')->willReturn(0);
			$mock->method('lastItemIdsForUser')->willReturn([]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === UnlockService::class) {
			$mock = $this->createMock(UnlockService::class);
			$mock->method('verify')->willReturn([
				'unlockToken' => 'tok',
				'expiresAt' => time() + 60,
				'userDisplayName' => 'Alice',
				'userId' => 'alice',
				'isKitchenAdmin' => true,
				'hospitalityAllowed' => true,
			]);
			$mock->method('peekUnlockToken')->willReturn(['userId' => 'alice', 'displayName' => 'Alice', 'userDisplayName' => 'Alice']);
			$mock->method('consumeUnlockToken')->willReturn(['userId' => 'alice']);
			$mock->method('setPin')->willReturnCallback(static function (): void {});
			$mock->method('setQr')->willReturnCallback(static function (): void {});
			$mock->method('invalidateUnlockToken')->willReturnCallback(static function (): void {});
			return $this->byType[$key] = $mock;
		}

		if ($typeName === SubsidyService::class) {
			$mock = $this->createMock(SubsidyService::class);
			$mock->method('computeForUser')->willReturn([
				'gross_cents' => 0,
				'subsidy_cents' => 0,
				'deduct_cents' => 0,
			]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === SettingsService::class) {
			$mock = $this->createMock(SettingsService::class);
			$mock->method('isHospitalityEnabled')->willReturn(false);
			$mock->method('isMultiSiteEnabled')->willReturn(false);
			$mock->method('getAccessMode')->willReturn('open');
			$mock->method('getAll')->willReturn([]);
			$mock->method('getAppAdmins')->willReturn(['alice']);
			$mock->method('getSubsidyAllowanceCents')->willReturn(0);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === RateLimitService::class) {
			$mock = $this->createMock(RateLimitService::class);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === IURLGenerator::class) {
			$mock = $this->createMock(IURLGenerator::class);
			$mock->method('linkToRoute')->willReturn('/apps/snackcheck/log');
			$mock->method('linkToRouteAbsolute')->willReturn('http://localhost/apps/snackcheck/settings/license');
			$mock->method('getAbsoluteURL')->willReturn('http://localhost/');
			$mock->method('imagePath')->willReturn('/apps/snackcheck/img/app.svg');
			return $this->byType[$key] = $mock;
		}

		if ($typeName === IL10N::class) {
			$mock = $this->createMock(IL10N::class);
			$mock->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
			$mock->method('getLanguageCode')->willReturn('en');
			return $this->byType[$key] = $mock;
		}

		if ($typeName === SettingsSectionCatalog::class) {
			$real = new SettingsSectionCatalog($this->mockFor(IL10N::class, true, 'happy', $controllerClass));
			return $this->byType[$key] = $real;
		}

		if ($typeName === ITimeFactory::class) {
			$mock = $this->createMock(ITimeFactory::class);
			$mock->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));
			$mock->method('getTime')->willReturn(time());
			return $this->byType[$key] = $mock;
		}

		if ($typeName === PayrollExportService::class) {
			$mock = $this->createMock(PayrollExportService::class);
			$mock->method('buildPersonalPackage')->willReturn([
				'reconcileOk' => true,
				'lines' => [],
				'sheets' => [],
			]);
			$mock->method('buildHospitalityRows')->willReturn([]);
			$mock->method('toCsv')->willReturn("a,b\n");
			$mock->method('toXlsx')->willReturn('PK');
			return $this->byType[$key] = $mock;
		}

		if ($typeName === ComplimentaryExportService::class) {
			$mock = $this->createMock(ComplimentaryExportService::class);
			$mock->method('buildRows')->willReturn([]);
			$mock->method('toCsv')->willReturn("a\n");
			return $this->byType[$key] = $mock;
		}

		if ($typeName === BrAggregateService::class) {
			$mock = $this->createMock(BrAggregateService::class);
			$mock->method('buildForPeriod')->willReturn(['rows' => [], 'byItem' => []]);
			$mock->method('buildForOpenPeriod')->willReturn(['rows' => [], 'byItem' => []]);
			$mock->method('forbiddenColumns')->willReturn([]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === PulseService::class) {
			$mock = $this->createMock(PulseService::class);
			$mock->method('buildForSite')->willReturn(['items' => [], 'shoppingList' => []]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === AdminTotalsService::class) {
			$mock = $this->createMock(AdminTotalsService::class);
			$mock->method('buildForOpenPeriod')->willReturn(['users' => []]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === AuditService::class) {
			$mock = $this->createMock(AuditService::class);
			$mock->method('recent')->willReturn([]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === ShelfQrService::class) {
			$mock = $this->createMock(ShelfQrService::class);
			$mock->method('svgForItem')->willReturn('<svg/>');
			$mock->method('absoluteShelfUrl')->willReturn('http://localhost/apps/snackcheck/shelf/1');
			return $this->byType[$key] = $mock;
		}

		if ($typeName === MyMonthStatementPresenter::class) {
			$mock = $this->createMock(MyMonthStatementPresenter::class);
			$mock->method('showSubsidy')->willReturn(false);
			$mock->method('formatEuroWeb')->willReturn('0,00');
			$mock->method('formatEuroPdf')->willReturn('0.00');
			$mock->method('breakdownRows')->willReturn([]);
			$mock->method('buildPdfDocument')->willReturn([
				'brand' => 'SnackCheck',
				'title' => 'My month',
				'meta' => [],
				'keyFigure' => ['label' => 'To deduct', 'value' => '0.00'],
				'breakdown' => [],
				'totalLine' => ['label' => 'TOTAL', 'value' => '0.00'],
				'tableTitle' => 'Items',
				'columns' => ['Item'],
				'colWidths' => [1.0],
				'rows' => [],
				'note' => '',
				'emptyItemsText' => 'None',
			]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === HospAllowMapper::class) {
			$mock = $this->createMock(HospAllowMapper::class);
			$mock->method('isAllowed')->willReturn(true);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === ConsumptionLogMapper::class) {
			$mock = $this->createMock(ConsumptionLogMapper::class);
			$mock->method('find')->willReturn($this->log());
			$mock->method('findForUserPeriod')->willReturn([]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === InstanceId::class) {
			$mock = $this->createMock(InstanceId::class);
			$mock->method('get')->willReturn('instance-x');
			return $this->byType[$key] = $mock;
		}

		if ($typeName === IUserManager::class) {
			$mock = $this->createMock(IUserManager::class);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$mock->method('get')->willReturn($user);
			$mock->method('search')->willReturn([]);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === IGroupManager::class) {
			$mock = $this->createMock(IGroupManager::class);
			$mock->method('isAdmin')->willReturn($allow);
			$mock->method('search')->willReturn([]);
			$mock->method('isInGroup')->willReturn(false);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === DigestMailService::class) {
			$mock = $this->createMock(DigestMailService::class);
			$mock->method('getDigestDaysBefore')->willReturn(3);
			return $this->byType[$key] = $mock;
		}

		if ($typeName === LicenseEnforcementService::class) {
			return $this->byType[$key] = $this->createMock(LicenseEnforcementService::class);
		}

		try {
			return $this->byType[$key] = $this->createMock($typeName);
		} catch (\Throwable) {
			return $this->byType[$key] = new \stdClass();
		}
	}

	/** @param class-string $controllerClass */
	private function request(string $mode, string $controllerClass, bool $allow): IRequest
	{
		$mock = $this->createMock(IRequest::class);
		$params = [
			'siteId' => 1,
			'itemId' => 1,
			'qty' => 1,
			'mode' => 'self',
			'name' => 'Kaffee',
			'priceCents' => 50,
			'category' => 'drink',
			'confirm' => true,
			'reason' => 'test',
			'section' => 'access',
			'pin' => '1234',
			'qrPayload' => 'qr',
			'idempotencyKey' => 'idem-1',
			'licenseKey' => 'SNK2.x',
			'label' => 'Kitchen',
			'format' => 'csv',
			'q' => 'al',
			'userId' => 'alice',
			'unlockToken' => 'tok',
			'targetUserId' => 'alice',
			'deviceId' => 7,
			'hospitalityEnabled' => false,
		];
		$mock->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return $params[$key] ?? $default;
			}
		);
		$mock->method('getParams')->willReturn($params);
		$headers = [
			'Authorization' => $allow && $mode === 'happy' ? 'Bearer snkterm_x' : '',
			'Idempotency-Key' => 'idem-1',
			'X-Unlock-Token' => 'tok',
		];
		$mock->method('getHeader')->willReturnCallback(
			static function (string $name) use ($headers): string {
				return $headers[$name] ?? $headers[ucwords($name, '-')] ?? '';
			}
		);
		$mock->method('getRemoteAddress')->willReturn('127.0.0.1');
		$mock->method('getUploadedFile')->willReturn([
			'tmp_name' => '/tmp/x',
			'error' => UPLOAD_ERR_OK,
			'size' => 10,
			'type' => 'image/png',
			'name' => 'x.png',
		]);
		return $mock;
	}

	/** @return list<mixed> */
	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				$args[] = match ($type->getName()) {
					'int' => 1,
					'string' => 'access',
					'bool' => true,
					'array' => [],
					'float' => 1.0,
					default => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
				};
				continue;
			}
			$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
		}
		return $args;
	}

	private function site(): Site
	{
		$s = new Site();
		$s->setId(1);
		$s->setCode('DEFAULT');
		$s->setName('Default');
		$s->setActive(1);
		$s->setManagersJson('["alice"]');
		return $s;
	}

	private function period(): Period
	{
		$p = new Period();
		$p->setId(1);
		$p->setLabel('2026-08');
		$p->setState('open');
		return $p;
	}

	private function item(): CatalogItem
	{
		$i = new CatalogItem();
		$i->setId(1);
		$i->setSiteId(1);
		$i->setName('Kaffee');
		$i->setPriceCents(50);
		$i->setCategory('drink');
		$i->setActive(1);
		$i->setOnHand(10);
		$i->setTagsJson('[]');
		return $i;
	}

	private function log(): ConsumptionLog
	{
		$l = new ConsumptionLog();
		$l->setId(1);
		$l->setLineTotalCents(50);
		$l->setSiteId(1);
		$l->setUserId('alice');
		return $l;
	}

	private function device(): TerminalDevice
	{
		$d = new TerminalDevice();
		$d->setId(7);
		$d->setSiteId(1);
		$d->setLabel('Kitchen');
		$d->setTokenHash('x');
		return $d;
	}
}

/** @internal Atlas happy-path — injects JSON body without php://input */
final class AtlasTestableDeviceApiController extends DeviceApiController
{
	/** @var array<string, mixed> */
	public array $forcedJsonBody = [];

	protected function jsonBody(): array
	{
		return $this->forcedJsonBody !== [] ? $this->forcedJsonBody : parent::jsonBody();
	}
}
