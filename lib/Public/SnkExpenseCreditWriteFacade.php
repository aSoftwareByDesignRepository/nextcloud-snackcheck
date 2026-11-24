<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Public;

/**
 * SnackCheck expense-credit write contract — capability `check:expense-credits`
 * (exposed as `snackcheck.expense-credits = {version: FACADE_VERSION}`).
 *
 * First-party companion apps (ExpenseCheck) resolve the implementation via
 * `\OCP\Server::get(SnkExpenseCreditWriteFacade::class)`, guarded by
 * `isEnabledForUser('snackcheck')` + `interface_exists` — absence ⇒ degrade.
 *
 * Rows written through this facade carry source='expense_claim' and stay
 * write-locked to the caller while the `check:expense-claims` capability is
 * present (single-writer-per-origin rule): web self-undo sees a uniform 404,
 * manager void gets 409 managed_externally.
 */
interface SnkExpenseCreditWriteFacade
{
	public const FACADE_VERSION = 1;

	/**
	 * Create (or idempotently replay) a personal_credit ledger row.
	 *
	 * @param array{
	 *   userUid: string,
	 *   actorUid: string,
	 *   siteId: int,
	 *   amountCents: int,
	 *   reason: string,
	 *   extRef: string
	 * } $input  extRef format '<appid>:<type>:<id>' (e.g. 'expensecheck:claim:42')
	 *          — the idempotency key becomes 'ext:' . extRef.
	 * @return array{logId:int, lineTotalCents:int, replay:bool}
	 * @throws SnkCreditException typed error — ->errorCode carries the stable code
	 */
	public function createCredit(array $input): array;

	/**
	 * Settlement reversal: voids the credit written for $extRef.
	 * Returns false when no matching non-voided row exists — safe to retry.
	 *
	 * @throws SnkCreditException 'period_closed' when the row exists in a closed
	 *         period (loud failure — org must reopen or accept), 'validation_failed'
	 *         for a malformed extRef/reason.
	 */
	public function voidCredit(string $extRef, string $reason, string $actorUid): bool;
}
