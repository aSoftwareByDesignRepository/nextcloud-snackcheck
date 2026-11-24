<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Public\SnkCreditException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\ExpenseCreditWriteFacade;
use OCA\SnackCheck\Service\SubsidyService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * F-SN1 facade contract (AC-INT-1): exactly-once settle via ext:-namespaced
 * idempotency keys; voidCredit is idempotent (true → false); input contract
 * and error-code taxonomy match the published interface.
 */
class ExpenseCreditWriteFacadeTest extends TestCase
{
	private function row(int $id = 7): ConsumptionLog
	{
		$log = new ConsumptionLog();
		$log->setId($id);
		$log->setLineTotalCents(-2540);
		$log->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);
		$log->setSource('expense_claim');
		return $log;
	}

	private function facade(
		?ConsumptionLogService $logs = null,
		?ConsumptionLog $found = null,
		bool $canAccess = true,
		array $knownUids = ['alice', 'bob'],
	): ExpenseCreditWriteFacade {
		$logs ??= $this->createMock(ConsumptionLogService::class);
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findByIdempotencyKey')->willReturn($found);
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->willReturn($canAccess);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($knownUids) {
			if (!in_array($uid, $knownUids, true)) {
				return null;
			}
			$u = $this->createMock(IUser::class);
			$u->method('getUID')->willReturn($uid);
			return $u;
		});
		return new ExpenseCreditWriteFacade($logs, $mapper, $access, $users);
	}

	private function input(array $over = []): array
	{
		return array_merge([
			'userUid' => 'bob',
			'actorUid' => 'alice',
			'siteId' => 1,
			'amountCents' => 2540,
			'reason' => 'ExpenseCheck #42 — Edeka Einkauf',
			'extRef' => 'expensecheck:claim:42',
		], $over);
	}

	public function testCreateCreditReturnsLogIdReplayTuple(): void
	{
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->expects($this->once())->method('createExpense')
			->with($this->callback(fn (array $in) =>
				$in['idempotencyKey'] === 'ext:expensecheck:claim:42'
				&& $in['source'] === 'expense_claim'
				&& $in['facade'] === true
				&& $in['targetUserId'] === 'bob'
				&& $in['actorUserId'] === 'alice'
				&& $in['extRef'] === 'expensecheck:claim:42'))
			->willReturn(['log' => $this->row(77), 'replay' => false, 'httpStatus' => 201]);

		$r = $this->facade($logs)->createCredit($this->input());
		self::assertSame(['logId' => 77, 'lineTotalCents' => -2540, 'replay' => false], $r);
	}

	public function testReplayReturnsSameLogId(): void
	{
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->method('createExpense')
			->willReturn(['log' => $this->row(77), 'replay' => true, 'httpStatus' => 200]);
		$r = $this->facade($logs)->createCredit($this->input());
		self::assertSame(77, $r['logId']);
		self::assertTrue($r['replay']);
	}

	public function testExtRefFormatIsEnforced(): void
	{
		foreach (['', 'nocolon', 'UPPER:claim:1', 'a-b:claim:1', 'expensecheck::42',
			str_repeat('x', 200) . ':claim:1'] as $bad) {
			try {
				$this->facade()->createCredit($this->input(['extRef' => $bad]));
				self::fail("extRef '$bad' accepted");
			} catch (SnkCreditException $e) {
				self::assertSame('ext_ref_invalid', $e->errorCode);
			}
		}
		// boundary shapes that must pass
		foreach (['expensecheck:claim:42', 'a:x:1', 'myapp:settle-2026_11:anything'] as $ok) {
			$logs = $this->createMock(ConsumptionLogService::class);
			$logs->method('createExpense')
				->willReturn(['log' => $this->row(), 'replay' => false, 'httpStatus' => 201]);
			$this->facade($logs)->createCredit($this->input(['extRef' => $ok]));
		}
		self::assertTrue(true);
	}

	public function testUnknownActorIsValidationFailed(): void
	{
		try {
			$this->facade(knownUids: ['bob'])->createCredit($this->input());
			self::fail('ghost actor accepted');
		} catch (SnkCreditException $e) {
			self::assertSame('validation_failed', $e->errorCode);
		}
	}

	public function testTargetWithoutAccessIsTargetNoAccess(): void
	{
		try {
			$this->facade(canAccess: false)->createCredit($this->input());
			self::fail('no-access target accepted');
		} catch (SnkCreditException $e) {
			self::assertSame('target_no_access', $e->errorCode);
		}
		try {
			$this->facade(knownUids: ['alice'])->createCredit($this->input());
			self::fail('ghost target accepted');
		} catch (SnkCreditException $e) {
			self::assertSame('target_no_access', $e->errorCode);
		}
	}

	public function testDomainExceptionsBecomeTypedSnkCredit(): void
	{
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->method('createExpense')->willThrowException(
			new DomainException('period_closed', 'Period closed', 409));
		try {
			$this->facade($logs)->createCredit($this->input());
			self::fail('closed period accepted');
		} catch (SnkCreditException $e) {
			self::assertSame('period_closed', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
		}
	}

	public function testVoidCreditTrueThenFalse(): void
	{
		$row = $this->row();
		$voidedLog = new \OCA\SnackCheck\Db\ConsumptionLog();
		$voidedLog->setVoidedBy('alice');
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->expects($this->once())->method('facadeVoidExpense')
			->with(7, 'alice', 'claim reversed')
			->willReturn($voidedLog);

		$f = $this->facade($logs, $row);
		self::assertTrue($f->voidCredit('expensecheck:claim:42', 'claim reversed', 'alice'));

		// Second call: the row now reports voided → false, no second void.
		$row->setVoidedAt(new \DateTime('2026-08-10 13:00:00'));
		self::assertFalse($f->voidCredit('expensecheck:claim:42', 'claim reversed', 'alice'));
	}

	public function testVoidCreditConcurrentWinnerIsFalse(): void
	{
		// Another actor's void landed between our read and the UPDATE — the row
		// is voided, but not by us: idempotent contract reports false.
		$row = $this->row();
		$voidedLog = new \OCA\SnackCheck\Db\ConsumptionLog();
		$voidedLog->setVoidedBy('carol');
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->expects($this->once())->method('facadeVoidExpense')
			->willReturn($voidedLog);
		self::assertFalse(
			$this->facade($logs, $row)->voidCredit('expensecheck:claim:42', 'r', 'alice'));
	}

	public function testVoidCreditMissingRowIsFalse(): void
	{
		self::assertFalse(
			$this->facade(found: null)->voidCredit('expensecheck:claim:99', 'r', 'alice'));
	}

	public function testVoidCreditRejectsForeignRows(): void
	{
		// A web-entered credit under an ext: key is not facade-owned → false.
		$row = $this->row();
		$row->setSource('expense_web');
		self::assertFalse(
			$this->facade(found: $row)->voidCredit('expensecheck:claim:42', 'r', 'alice'));
		// A non-credit row can never be voided via the facade either.
		$row2 = $this->row();
		$row2->setBillingBucket('personal');
		self::assertFalse(
			$this->facade(found: $row2)->voidCredit('expensecheck:claim:42', 'r', 'alice'));
	}

	public function testVoidCreditMalformedExtRefThrows(): void
	{
		try {
			$this->facade()->voidCredit('garbage', 'reason', 'alice');
			self::fail('malformed extRef accepted');
		} catch (SnkCreditException $e) {
			self::assertSame('ext_ref_invalid', $e->errorCode);
		}
	}

	public function testVoidCreditClosedPeriodPropagates(): void
	{
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->method('facadeVoidExpense')->willThrowException(
			new DomainException('period_closed', 'Cannot void in closed period', 409));
		try {
			$this->facade($logs, $this->row())->voidCredit('expensecheck:claim:42', 'r', 'alice');
			self::fail('closed period swallowed');
		} catch (SnkCreditException $e) {
			self::assertSame('period_closed', $e->errorCode);
		}
	}
}
