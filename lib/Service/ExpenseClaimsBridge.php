<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Exception\DomainException;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * Detection + access seam for the ExpenseCheck companion capability
 * `check:expense-claims`. EXP is optional — every call fails soft when the
 * app (or its published ingress contract) is absent.
 *
 * EXP ships the sibling ingress as an HTTP endpoint (`POST
 * /apps/expensecheck/api/ingress/drafts`, session-authenticated). A server-side
 * caller cannot replay the member's session, so the bridge invokes the exact
 * same services the ingress controller runs — ClaimLifecycleService for the
 * draft, ClaimLineService for provenance-stamped lines, ClaimMapper for the
 * `<uid>:ingress:<key>` request_key dedupe convention. Nothing is trusted:
 * EXP revalidates every field exactly as if typed into its own form.
 */
class ExpenseClaimsBridge
{
	public const EXP_APP_ID = 'expensecheck';
	/** F24 contract marker: EXP publishes its draft-ingress surface as this controller. */
	public const INGRESS_MARKER = 'OCA\\ExpenseCheck\\Controller\\IngressApiController';

	private const LIFECYCLE = 'OCA\\ExpenseCheck\\Service\\ClaimLifecycleService';
	private const EXP_ACCESS = 'OCA\\ExpenseCheck\\Service\\AccessControlService';
	private const LINE_SERVICE = 'OCA\\ExpenseCheck\\Service\\ClaimLineService';
	private const CLAIM_MAPPER = 'OCA\\ExpenseCheck\\Db\\ClaimMapper';
	private const CATEGORY_MAPPER = 'OCA\\ExpenseCheck\\Db\\CategoryMapper';
	private const COST_CENTER_MAPPER = 'OCA\\ExpenseCheck\\Db\\CostCenterMapper';
	private const EXP_VALIDATION = 'OCA\\ExpenseCheck\\Exception\\ValidationException';
	private const EXP_DOMAIN = 'OCA\\ExpenseCheck\\Exception\\DomainException';

	public const SOURCE_APP = 'snackcheck';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function capabilityPresent(): bool
	{
		return $this->appManager->isEnabledForUser(self::EXP_APP_ID)
			&& class_exists(self::INGRESS_MARKER);
	}

	/**
	 * Effective claim-routing readiness: capability live AND the org's
	 * category/cost-center mapping resolves inside EXP. Everything else
	 * degrades to ledger routing — same semantics as an absent capability.
	 */
	public function routingReady(int $categoryId, int $costCenterId): bool
	{
		return $this->capabilityPresent() && $this->mappingConfigured($categoryId, $costCenterId);
	}

	/**
	 * True when the org-configured EXP mapping ids resolve to live EXP
	 * entities. A stale/missing mapping degrades the route exactly like an
	 * absent capability (expense_route_unavailable at write time).
	 */
	public function mappingConfigured(int $categoryId, int $costCenterId): bool
	{
		if ($categoryId <= 0 || $costCenterId <= 0) {
			return false;
		}
		try {
			$category = Server::get(self::CATEGORY_MAPPER)->findById($categoryId);
			if ($category === null || $category->getRequiresAttendees() === 1) {
				// Bewirtung-class categories legally need attendees+occasion —
				// SNK never collects them, so such a mapping can never satisfy
				// EXP's compliance gate.
				return false;
			}
			return Server::get(self::COST_CENTER_MAPPER)->findById($costCenterId) !== null;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Forward one expense entry as an EXP draft claim + receipt line.
	 * Idempotent on $sourceRef via EXP's request_key convention — a replayed
	 * submit returns the existing draft instead of a second claim.
	 *
	 * @return array{ref:string,deduplicated:bool,url?:string}
	 *         `url` is emitted only when the ACTING uid can actually open the
	 *         EXP claim page (access door + claim visibility) — otherwise the
	 *         response carries the ref without a dead 'Open claim' action.
	 * @throws DomainException expense_route_unavailable (EXP absent/mid-flight)
	 *         or validation_failed (EXP rejected a field).
	 */
	public function submitDraft(
		string $memberUid,
		int $amountCents,
		string $reason,
		string $sourceRef,
		int $categoryId,
		int $costCenterId,
		?string $actorUid = null,
	): array {
		// The claim belongs to the member; the audit chain attributes line
		// writes to whoever actually recorded them (manager proxy ≠ member).
		$actorUid = $actorUid ?? $memberUid;
		try {
			$claimMapper = Server::get(self::CLAIM_MAPPER);
			$requestKey = $memberUid . ':ingress:' . $sourceRef;
			$title = mb_substr('SnackCheck: ' . trim($reason), 0, 191);
			$title = $title !== 'SnackCheck:' ? $title : 'SnackCheck expense';
			$existing = self::awaitSettledClaim($claimMapper, $requestKey);
			if ($existing !== null) {
				self::assertReplayMatches($existing, $amountCents, $title);
				return $this->claimResult($existing, $actorUid, true);
			}

			$claim = null;
			try {
				$claim = Server::get(self::LIFECYCLE)->createDraft(
					$memberUid,
					$title,
					'EUR',
					$requestKey,
					['kind' => 'standard'],
				);
			} catch (\Throwable $e) {
				// TOCTOU: a concurrent submit can win the request_key unique
				// index between our findByRequestKey probe and this insert —
				// the honest answer is the existing draft, not an error.
				if (self::isExpUniqueViolation($e)) {
					$winner = self::awaitSettledClaim($claimMapper, $requestKey);
					if ($winner !== null) {
						self::assertReplayMatches($winner, $amountCents, $title);
						return $this->claimResult($winner, $actorUid, true);
					}
				}
				throw $e;
			}
			try {
				Server::get(self::LINE_SERVICE)->addLine($claim, [
					'line_type' => 'receipt',
					'occurred_on' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
					'category_id' => $categoryId,
					'amount' => number_format($amountCents / 100, 2, '.', ''),
					'merchant' => 'SnackCheck',
					'description' => mb_substr($reason, 0, 2000),
					'source_app' => self::SOURCE_APP,
					'source_ref' => $sourceRef,
				], [[
					'cost_center_id' => $costCenterId,
					'amount_minor' => $amountCents,
				]], $actorUid);
			} catch (\Throwable $e) {
				// Partial failure: the draft insert already committed — an empty
				// orphan claim (plus its 'created' chain event) must not survive
				// a rejected line. EXP has no claim-delete; remove the composite
				// write's rows atomically.
				try {
					$db = Server::get(\OCP\IDBConnection::class);
					$db->beginTransaction();
					$db->executeStatement(
						'DELETE FROM *PREFIX*exp_claim_allocations WHERE line_id IN (SELECT id FROM *PREFIX*exp_claim_lines WHERE claim_id = ?)',
						[$claim->getId()],
					);
					$db->executeStatement('DELETE FROM *PREFIX*exp_claim_lines WHERE claim_id = ?', [$claim->getId()]);
					$db->executeStatement('DELETE FROM *PREFIX*exp_claim_events WHERE claim_id = ?', [$claim->getId()]);
					$db->executeStatement('DELETE FROM *PREFIX*exp_claims WHERE id = ?', [$claim->getId()]);
					$db->commit();
				} catch (\Throwable $cleanup) {
					try {
						$db->rollBack();
					} catch (\Throwable) {
					}
					// Compensation is best-effort; the original error is the
					// one that matters to the caller.
				}
				throw $e;
			}

			return $this->claimResult($claim, $actorUid, false);
		} catch (DomainException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw self::mapExpError($e);
		}
	}

	/**
	 * A concurrent createDraft commits the claim row (with its request_key)
	 * before its first line lands — total_minor reads 0 for that window, so a
	 * dedupe hit would compare a half-written fingerprint and spuriously
	 * conflict. Poll briefly until the line's amount settles (or the row
	 * vanishes under compensation), then compare the completed claim.
	 */
	private static function awaitSettledClaim(object $claimMapper, string $requestKey): ?object
	{
		$claim = null;
		for ($i = 0; $i < 40; $i++) {
			$claim = $claimMapper->findByRequestKey($requestKey);
			if ($claim === null || (int)$claim->getTotalMinor() > 0) {
				return $claim;
			}
			usleep(50_000);
		}
		return $claim;
	}

	/**
	 * Idempotency-key reuse is only honest when the payload matches — same
	 * key + different amount or reason is an idempotency_conflict, exactly
	 * like the ledger path's canon-hash check (never a silent wrong replay).
	 */
	private static function assertReplayMatches(object $claim, int $amountCents, string $title): void
	{
		if ((int)$claim->getTotalMinor() !== $amountCents || (string)$claim->getTitle() !== $title) {
			throw new DomainException('idempotency_conflict', 'Idempotency conflict', 409);
		}
	}

	/** Unique-constraint detection across MySQL/PgSQL/SQLite (EXP's own taxonomy). */
	private static function isExpUniqueViolation(\Throwable $e): bool
	{
		$sqlErrors = 'OCA\\ExpenseCheck\\Db\\SqlErrors';
		if (class_exists($sqlErrors)) {
			return (bool)$sqlErrors::isUniqueViolation($e);
		}
		return str_contains(strtolower($e->getMessage()), 'unique')
			|| str_contains(strtolower($e->getMessage()), 'duplicate');
	}

	/**
	 * Collapse sibling errors onto the SNK contract surface: EXP field
	 * rejections become validation_failed, everything else is the uniform
	 * route outage (the raw EXP code stays in the message for support).
	 */
	private static function mapExpError(\Throwable $e): DomainException
	{
		$validation = self::EXP_VALIDATION;
		$domain = self::EXP_DOMAIN;
		if ($e instanceof $validation) {
			$msg = $e->getMessage() !== '' ? $e->getMessage() : 'ExpenseCheck rejected the entry';
			return new DomainException('validation_failed', $msg, 422);
		}
		if ($e instanceof $domain) {
			$msg = $e->getMessage() !== '' ? $e->getMessage() : 'ExpenseCheck unavailable';
			return new DomainException('expense_route_unavailable', $msg, 409);
		}
		return new DomainException('expense_route_unavailable', 'ExpenseCheck unavailable', 409);
	}

	/**
	 * Response shape for all three claim outcomes. The `url` key exists only
	 * when the ACTING uid can open the EXP claim page — a link the role
	 * cannot follow is a dead CTA, so ineligible actors get the ref only.
	 *
	 * @return array{ref:string,deduplicated:bool,url?:string}
	 */
	private function claimResult(object $claim, string $actorUid, bool $deduplicated): array
	{
		$out = [
			'ref' => (string)$claim->getClaimNo(),
			'deduplicated' => $deduplicated,
		];
		$url = $this->claimUrlForActor($claim, $actorUid);
		if ($url !== null) {
			$out['url'] = $url;
		}
		return $out;
	}

	/**
	 * The 'Open claim' affordance is honest only when the acting uid can
	 * actually reach /apps/expensecheck/claims/<id>: EXP's access door
	 * (canUseApp) AND that claim's visibility (owner / EXP admin / finance /
	 * auditor / pending decision rights — canView). Fail-closed: any doubt
	 * (service absent, probe throws) omits the url — the submit still
	 * reports its ref, and a page-reachable role can navigate manually.
	 */
	private function claimUrlForActor(object $claim, string $actorUid): ?string
	{
		try {
			if (!Server::get(self::EXP_ACCESS)->canUseApp($actorUid)) {
				return null;
			}
			if (!Server::get(self::LIFECYCLE)->canView($claim, $actorUid)) {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}
		return $this->claimUrl($claim->getId());
	}

	private function claimUrl(int $claimId): string
	{
		// linkToRoute cannot resolve a sibling app's route table outside the
		// sibling's own request — build the stable detail path directly.
		return $this->urlGenerator->linkTo('', 'apps/' . self::EXP_APP_ID . '/claims/' . $claimId);
	}
}
