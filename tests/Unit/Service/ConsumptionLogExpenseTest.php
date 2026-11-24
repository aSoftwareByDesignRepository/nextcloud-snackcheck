<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\HospAllowMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SubsidyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * createExpense: negative ledger rows for out-of-pocket credits.
 * Guards under test: feature toggles, self-entry cap, uniform-422 target gate,
 * ext: key reservation, reason bounds, idempotent replay/fingerprint, audit.
 */
class ConsumptionLogExpenseTest extends TestCase
{
	private function makeService(
		?ConsumptionLog $existing = null,
		array $settings = [],
		?ConsumptionLog &$inserted = null,
		?AuditService &$audit = null,
		?IUserManager $users = null,
		?AccessControlService $access = null,
		bool $periodOpen = true,
		?\OCP\Notification\IManager $notifications = null,
	): ConsumptionLogService {
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn($existing);
		$mapper->method('insert')->willReturnCallback(static function (ConsumptionLog $log) use (&$inserted): ConsumptionLog {
			$log->setId(99);
			$inserted = $log;
			return $log;
		});

		$period = new Period();
		$period->setId(1);
		$period->setState($periodOpen ? 'open' : 'closed');
		$period->setLabel('2026-08');

		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($periodOpen ? $period : null);

		$periods = $this->createMock(PeriodService::class);
		$periods->method('getOpenOrFail')->willReturn($period);

		$settingsMock = $this->createMock(SettingsService::class);
		$settingsMock->method('isExpenseEnabled')->willReturn($settings['enabled'] ?? true);
		$settingsMock->method('isExpenseSelfEntryEnabled')->willReturn($settings['selfEntry'] ?? true);
		$settingsMock->method('getExpenseSelfEntryMaxCents')->willReturn($settings['selfMax'] ?? 0);

		$audit = $this->createMock(AuditService::class);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10 12:00:00'));
		$time->method('getTime')->willReturn((new \DateTime('2026-08-10 12:00:00'))->getTimestamp());

		$users ??= $this->directoryUsers(['alice', 'bob', 'admin']);
		$access ??= $this->accessAllowing();

		return new ConsumptionLogService(
			$mapper, $periodMapper, $this->createMock(CatalogService::class), $periods,
			$settingsMock, $audit, $this->createMock(HospAllowMapper::class),
			$access, $db, $time, $users, null, $notifications
		);
	}

	/** @param list<string> $uids */
	private function directoryUsers(array $uids): IUserManager
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($uids) {
			if (!in_array($uid, $uids, true)) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($uid);
			return $user;
		});
		return $users;
	}

	private function accessAllowing(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->willReturn(true);
		$access->method('canManageSite')->willReturn(true);
		return $access;
	}

	private function input(array $over = []): array
	{
		return array_merge([
			'amountCents' => 250,
			'expenseReason' => 'Milk for the kitchen',
			'idempotencyKey' => 'k-exp-1',
			'siteId' => 1,
			'actorUserId' => 'alice',
			'source' => 'expense_web',
		], $over);
	}

	public function testSelfExpenseWritesNegativeCreditRow(): void
	{
		$inserted = null;
		$audit = null;
		$svc = $this->makeService(null, [], $inserted, $audit);
		$audit->expects($this->once())->method('record')
			->with('alice', 'expense.create', 'consumption_log', '99',
				$this->callback(fn (array $p) => $p['amount_cents'] === 250 && !isset($p['expenseReason']) && !isset($p['reason'])));

		$r = $svc->createExpense($this->input());

		self::assertFalse($r['replay']);
		self::assertSame(201, $r['httpStatus']);
		$log = $r['log'];
		self::assertSame(-250, $log->getLineTotalCents());
		self::assertSame(-250, $log->getUnitPriceCents());
		self::assertSame(SubsidyService::BUCKET_PERSONAL_CREDIT, $log->getBillingBucket());
		self::assertSame('expense_web', $log->getSource());
		self::assertSame('alice', $log->getUserId());
		self::assertSame('alice', $log->getLoggedBy());
		self::assertSame('Milk for the kitchen', $log->getExpenseReason());
		self::assertSame('k-exp-1', $log->getIdempotencyKey());
		self::assertNull($log->getItemId());
		self::assertSame('Expense', $log->getItemNameSnap());
		self::assertSame(1, $log->getQty());
	}

	public function testDisabledFeatureIs403(): void
	{
		$svc = $this->makeService(null, ['enabled' => false]);
		$this->expectDomain('expense_disabled', 403);
		$svc->createExpense($this->input());
	}

	public function testSelfEntryOffIs403(): void
	{
		$svc = $this->makeService(null, ['selfEntry' => false]);
		$this->expectDomain('expense_self_entry_disabled', 403);
		$svc->createExpense($this->input());
	}

	public function testSelfEntryCapEnforcedOnlyAboveZero(): void
	{
		$svc = $this->makeService(null, ['selfMax' => 100]);
		$this->expectDomain('amount_over_self_limit', 422);
		$svc->createExpense($this->input(['amountCents' => 101]));
	}

	public function testSelfEntryCapZeroMeansNoLimit(): void
	{
		$svc = $this->makeService(null, ['selfMax' => 0]);
		$r = $svc->createExpense($this->input(['amountCents' => 9_999]));
		self::assertSame(201, $r['httpStatus']);
	}

	public function testAmountBounds(): void
	{
		$svc = $this->makeService();
		foreach ([0, -1, -250, 99_999_999 + 1] as $bad) {
			try {
				$svc->createExpense($this->input(['amountCents' => $bad]));
				self::fail("amount $bad accepted");
			} catch (DomainException $e) {
				self::assertSame('amount_invalid', $e->errorCode);
			}
		}
	}

	public function testReasonBounds(): void
	{
		$svc = $this->makeService();
		foreach (['', 'ab', '  ', str_repeat('x', 501)] as $bad) {
			try {
				$svc->createExpense($this->input(['expenseReason' => $bad]));
				self::fail('reason accepted');
			} catch (DomainException $e) {
				self::assertSame('expense_reason_required', $e->errorCode);
			}
		}
		$r = $svc->createExpense($this->input(['expenseReason' => str_repeat('y', 500)]));
		self::assertSame(201, $r['httpStatus']);
		// Lower boundary: exactly 3 chars must pass (kills < → <= mutants).
		$svc2 = $this->makeService();
		$r2 = $svc2->createExpense($this->input(['expenseReason' => 'abc']));
		self::assertSame(201, $r2['httpStatus']);
	}

	public function testSelfEntryCapBoundaryExactIsAllowed(): void
	{
		$svc = $this->makeService(null, ['selfMax' => 250]);
		$r = $svc->createExpense($this->input(['amountCents' => 250]));
		self::assertSame(201, $r['httpStatus']);
	}

	public function testMaxExpenseBoundaryExactIsAllowed(): void
	{
		$svc = $this->makeService();
		$r = $svc->createExpense($this->input(['amountCents' => 99_999_999]));
		self::assertSame(201, $r['httpStatus']);
	}

	public function testExtPrefixedKeyRejectedForHumans(): void
	{
		$svc = $this->makeService();
		$this->expectDomain('idempotency_key_reserved', 422);
		$svc->createExpense($this->input(['idempotencyKey' => 'ext:exp:123']));
	}

	public function testFacadeMayUseExtKey(): void
	{
		$svc = $this->makeService();
		$r = $svc->createExpense($this->input([
			'idempotencyKey' => 'ext:exp:123',
			'facade' => true,
			'source' => 'expense_claim',
		]));
		self::assertSame(201, $r['httpStatus']);
		self::assertSame('expense_claim', $r['log']->getSource());
	}

	public function testProxyRequiresKitchenAdmin(): void
	{
		$svc = $this->makeService();
		$this->expectDomain('permission_denied', 403);
		$svc->createExpense($this->input(['targetUserId' => 'bob', 'isKitchenAdmin' => false]));
	}

	public function testProxyToUnknownTargetIsUniform422(): void
	{
		$svc = $this->makeService();
		try {
			$svc->createExpense($this->input([
				'targetUserId' => 'ghost', 'isKitchenAdmin' => true, 'actorUserId' => 'admin',
			]));
			self::fail('ghost target accepted');
		} catch (DomainException $e) {
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
		}
	}

	public function testProxyToNoAccessTargetIsUniform422(): void
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->willReturn(false);
		$svc = $this->makeService(null, [], $n, $a, null, $access);
		$this->expectDomain('validation_failed', 422);
		$svc->createExpense($this->input([
			'targetUserId' => 'bob', 'isKitchenAdmin' => true, 'actorUserId' => 'admin',
		]));
	}

	public function testProxyCreditAttributesToTargetNotActor(): void
	{
		$inserted = null;
		$svc = $this->makeService(null, [], $inserted);
		$r = $svc->createExpense($this->input([
			'targetUserId' => 'bob', 'isKitchenAdmin' => true, 'actorUserId' => 'admin',
			'source' => 'expense_admin',
		]));
		self::assertSame('bob', $r['log']->getUserId());
		self::assertSame('admin', $r['log']->getLoggedBy());
	}

	public function testSelfTargetEqualsActorIsNotProxy(): void
	{
		// targetUserId === actor → self path (self-entry rules apply, no admin needed)
		$svc = $this->makeService();
		$r = $svc->createExpense($this->input(['targetUserId' => 'alice']));
		self::assertSame('alice', $r['log']->getUserId());
	}

	public function testReplayReturns200WithSameRow(): void
	{
		$existing = new ConsumptionLog();
		$existing->setId(42);
		$existing->setUserId('alice');
		$existing->setLoggedBy('alice');
		$existing->setSiteId(1);
		$existing->setLineTotalCents(-250);
		$existing->setExpenseReason('Milk for the kitchen');
		$existing->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);
		$existing->setCreatedAt(new \DateTime('2026-08-10 11:00:00'));

		$svc = $this->makeService($existing);
		$r = $svc->createExpense($this->input());
		self::assertTrue($r['replay']);
		self::assertSame(200, $r['httpStatus']);
		self::assertSame(42, $r['log']->getId());
	}

	public function testFingerprintMismatchIsConflict409(): void
	{
		$existing = new ConsumptionLog();
		$existing->setId(42);
		$existing->setUserId('alice');
		$existing->setLoggedBy('alice');
		$existing->setSiteId(1);
		$existing->setLineTotalCents(-999); // different amount → different fingerprint
		$existing->setExpenseReason('Milk for the kitchen');
		$existing->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);
		$existing->setCreatedAt(new \DateTime('2026-08-10 11:00:00'));

		$svc = $this->makeService($existing);
		$this->expectDomain('idempotency_conflict', 409);
		$svc->createExpense($this->input());
	}

	public function testReasonMismatchIsConflictNotReplay(): void
	{
		$existing = new ConsumptionLog();
		$existing->setId(42);
		$existing->setUserId('alice');
		$existing->setLoggedBy('alice');
		$existing->setSiteId(1);
		$existing->setLineTotalCents(-250);
		$existing->setExpenseReason('Coffee instead'); // body changed → conflict
		$existing->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);
		$existing->setCreatedAt(new \DateTime('2026-08-10 11:00:00'));

		$svc = $this->makeService($existing);
		$this->expectDomain('idempotency_conflict', 409);
		$svc->createExpense($this->input());
	}

	public function testClosedPeriodIs409(): void
	{
		$svc = $this->makeService(null, [], $n, $a, null, null, false);
		$this->expectDomain('period_closed', 409);
		$svc->createExpense($this->input());
	}

	public function testVoidFacadeManagedRejectsNonClaimRows(): void
	{
		$normal = new ConsumptionLog();
		$normal->setId(7);
		$normal->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);
		$normal->setSource('expense_web');

		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('lockRow')->willReturn($normal);
		$period = new Period();
		$period->setId(1);
		$period->setState('open');
		$periodMapper = $this->createMock(PeriodMapper::class);
		$periodMapper->method('lockRow')->willReturn($period);
		$db = $this->createMock(IDBConnection::class);
		$svc = new ConsumptionLogService(
			$mapper, $periodMapper, $this->createMock(CatalogService::class),
			$this->createMock(PeriodService::class), $this->createMock(SettingsService::class),
			$this->createMock(AuditService::class), $this->createMock(HospAllowMapper::class),
			$this->accessAllowing(), $db, $this->createMock(ITimeFactory::class),
			$this->directoryUsers(['alice'])
		);
		$this->expectDomain('managed_externally', 409);
		$svc->void(7, 'ext:writer', 'void reason', true, false, false, null, true);
	}

	private function expectDomain(string $code, int $status): void
	{
		$this->expected = [$code, $status];
		$this->expectException(DomainException::class);
	}

	public function testManagerCreditForOtherNotifiesMember(): void
	{
		$notification = $this->createMock(\OCP\Notification\INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->with('bob')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->with('expense_credit', '99')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with(\OCA\SnackCheck\Notification\Notifier::SUBJECT_EXPENSE_CREDIT, $this->callback(
				static fn(array $p): bool => $p['amount'] === '2.50' && $p['actor'] !== ''
			))->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();

		$notifications = $this->createMock(\OCP\Notification\IManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify')->with($notification);

		$svc = $this->makeService(notifications: $notifications);
		$res = $svc->createExpense($this->input([
			'targetUserId' => 'bob', 'isKitchenAdmin' => true, 'actorUserId' => 'admin',
			'source' => 'expense_admin',
		]));
		self::assertSame(201, $res['httpStatus']);
	}

	public function testSelfEntryStaysSilent(): void
	{
		$notifications = $this->createMock(\OCP\Notification\IManager::class);
		$notifications->expects($this->never())->method('createNotification');
		$svc = $this->makeService(notifications: $notifications);
		$res = $svc->createExpense($this->input());
		self::assertSame(201, $res['httpStatus']);
	}

	public function testTerminalProxyNotifiesAndClaimRowsStaySilent(): void
	{
		// Terminal proxy → notify.
		$notifications = $this->createMock(\OCP\Notification\IManager::class);
		$notifications->expects($this->once())->method('createNotification')
			->willReturn($this->notificationStub());
		$svc = $this->makeService(notifications: $notifications);
		$svc->createExpense($this->input([
			'targetUserId' => 'bob', 'isKitchenAdmin' => true, 'actorUserId' => 'admin',
			'source' => 'expense_terminal_proxy', 'deviceId' => '7',
		]));

		// Facade claim row → EXP owns its own notification, we stay silent.
		$notifications2 = $this->createMock(\OCP\Notification\IManager::class);
		$notifications2->expects($this->never())->method('createNotification');
		$svc2 = $this->makeService(notifications: $notifications2);
		$svc2->createExpense($this->input([
			'targetUserId' => 'bob', 'facade' => true,
			'idempotencyKey' => 'ext:expensecheck:claim:9',
			'source' => 'expense_claim',
		]));
	}

	public function testNotifierFailureNeverBreaksTheWrite(): void
	{
		$notifications = $this->createMock(\OCP\Notification\IManager::class);
		$notifications->method('createNotification')->willThrowException(new \RuntimeException('broker down'));
		$svc = $this->makeService(notifications: $notifications);
		$res = $svc->createExpense($this->input([
			'targetUserId' => 'bob', 'isKitchenAdmin' => true, 'actorUserId' => 'admin',
			'source' => 'expense_admin',
		]));
		self::assertSame(201, $res['httpStatus']);
	}

	private function notificationStub(): \OCP\Notification\INotification
	{
		$n = $this->createMock(\OCP\Notification\INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink', 'setMessage'] as $m) {
			$n->method($m)->willReturnSelf();
		}
		return $n;
	}

	/** @var array{0:string,1:int}|null */
	private ?array $expected = null;

	protected function onNotSuccessfulTest(\Throwable $t): never
	{
		if ($this->expected !== null && $t instanceof DomainException) {
			self::assertSame($this->expected[0], $t->errorCode);
			self::assertSame($this->expected[1], $t->httpStatus);
		}
		parent::onNotSuccessfulTest($t);
	}
}
