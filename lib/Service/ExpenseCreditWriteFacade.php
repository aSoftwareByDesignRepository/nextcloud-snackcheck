<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Public\SnkCreditException;
use OCA\SnackCheck\Public\SnkExpenseCreditWriteFacade;
use OCP\IUserManager;

/**
 * Implementation of the check:expense-credits write contract. Every write
 * flows through the same ledger machinery (period lock, idempotency, audit)
 * as web/device entries — only the gating differs (facade callers are
 * trusted for member targeting and bypass the self-entry knobs, while the
 * org master toggle expenseEntriesEnabled still applies).
 */
class ExpenseCreditWriteFacade implements SnkExpenseCreditWriteFacade
{
	private const EXT_KEY_PREFIX = 'ext:';
	/** '<appid>:<type>:<id>' — namespaced so two apps can never collide. */
	private const EXT_REF_PATTERN = '/^[a-z][a-z0-9]*:[a-z0-9_-]+:.+$/';
	private const EXT_REF_MAX_LEN = 120; // key budget: 'ext:' (4) + ref ≤ 128

	public function __construct(
		private readonly ConsumptionLogService $logs,
		private readonly ConsumptionLogMapper $mapper,
		private readonly AccessControlService $access,
		private readonly IUserManager $userManager,
	) {
	}

	public function createCredit(array $input): array
	{
		$extRef = trim((string)($input['extRef'] ?? ''));
		if ($extRef === '' || mb_strlen($extRef) > self::EXT_REF_MAX_LEN
			|| preg_match(self::EXT_REF_PATTERN, $extRef) !== 1) {
			throw new SnkCreditException('ext_ref_invalid', 'extRef must match "<appid>:<type>:<id>"', 422);
		}
		$actorUid = trim((string)($input['actorUid'] ?? ''));
		if ($actorUid === '' || $this->userManager->get($actorUid) === null) {
			throw new SnkCreditException('validation_failed', 'Unknown actor', 422);
		}
		$userUid = trim((string)($input['userUid'] ?? ''));
		if ($userUid === '' || $this->userManager->get($userUid) === null) {
			throw new SnkCreditException('target_no_access', 'Cannot log for this user', 422);
		}
		if (!$this->access->canAccessApp($userUid)) {
			throw new SnkCreditException('target_no_access', 'Cannot log for this user', 422);
		}
		try {
			$result = $this->logs->createExpense([
				'amountCents' => (int)($input['amountCents'] ?? 0),
				'expenseReason' => (string)($input['reason'] ?? ''),
				'idempotencyKey' => self::EXT_KEY_PREFIX . $extRef,
				'siteId' => (int)($input['siteId'] ?? 0),
				'actorUserId' => $actorUid,
				'targetUserId' => $userUid,
				'source' => 'expense_claim',
				'facade' => true,
				'extRef' => $extRef,
				'isKitchenAdmin' => true,
			]);
			$log = $result['log'];
			return [
				'logId' => (int)$log->getId(),
				'lineTotalCents' => (int)$log->getLineTotalCents(),
				'replay' => (bool)$result['replay'],
			];
		} catch (DomainException $e) {
			throw new SnkCreditException($e->errorCode, $e->getMessage(), $e->httpStatus);
		}
	}

	public function voidCredit(string $extRef, string $reason, string $actorUid): bool
	{
		$extRef = trim($extRef);
		if ($extRef === '' || mb_strlen($extRef) > self::EXT_REF_MAX_LEN
			|| preg_match(self::EXT_REF_PATTERN, $extRef) !== 1) {
			throw new SnkCreditException('ext_ref_invalid', 'extRef must match "<appid>:<type>:<id>"', 422);
		}
		$row = $this->mapper->findByIdempotencyKey(self::EXT_KEY_PREFIX . $extRef);
		if ($row === null
			|| $row->getVoidedAt() !== null
			|| $row->getBillingBucket() !== SubsidyService::BUCKET_PERSONAL_CREDIT
			|| $row->getSource() !== 'expense_claim') {
			return false;
		}
		try {
			$log = $this->logs->facadeVoidExpense((int)$row->getId(), $actorUid, $reason);
		} catch (DomainException $e) {
			throw new SnkCreditException($e->errorCode, $e->getMessage(), $e->httpStatus);
		}
		// Race honesty: a concurrent void may have won the row lock — then this
		// call was a no-op and must report false (idempotent void contract),
		// not claim a void it did not perform.
		return (string)$log->getVoidedBy() === $actorUid;
	}
}
