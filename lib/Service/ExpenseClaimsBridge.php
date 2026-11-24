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
	 * @return array{ref:string,url:string,deduplicated:bool}
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
	): array {
		try {
			$claimMapper = Server::get(self::CLAIM_MAPPER);
			$requestKey = $memberUid . ':ingress:' . $sourceRef;
			$existing = $claimMapper->findByRequestKey($requestKey);
			if ($existing !== null) {
				return [
					'ref' => (string)$existing->getClaimNo(),
					'url' => $this->claimUrl($existing->getId()),
					'deduplicated' => true,
				];
			}

			$title = mb_substr('SnackCheck: ' . trim($reason), 0, 191);
			$claim = Server::get(self::LIFECYCLE)->createDraft(
				$memberUid,
				$title !== 'SnackCheck:' ? $title : 'SnackCheck expense',
				'EUR',
				$requestKey,
				['kind' => 'standard'],
			);
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
			]], $memberUid);

			return [
				'ref' => (string)$claim->getClaimNo(),
				'url' => $this->claimUrl($claim->getId()),
				'deduplicated' => false,
			];
		} catch (DomainException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw self::mapExpError($e);
		}
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

	private function claimUrl(int $claimId): string
	{
		return $this->urlGenerator->linkToRoute('expensecheck.page.claimDetail', ['id' => $claimId]);
	}
}
