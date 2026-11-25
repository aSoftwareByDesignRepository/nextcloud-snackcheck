<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Integration;

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\ExpenseClaimsBridge;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * F-SN2 / F24 real-path proof: the bridge forwards an expense into the live
 * ExpenseCheck app as a draft claim + provenance-stamped receipt line, with
 * request_key dedupe collapsing resubmits into exactly one claim.
 *
 * Skips cleanly when ExpenseCheck is not enabled on this instance.
 */
final class ExpenseClaimsBridgeIntegrationTest extends TestCase
{
	private ExpenseClaimsBridge $bridge;
	private IDBConnection $db;
	private int $categoryId = 0;
	private int $costCenterId = 0;
	private int $attendeeCategoryId = 0;
	private string $uid;
	/** @var list<int> claim ids created by this run (cleaned up in tearDown). */
	private array $createdClaims = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			self::markTestSkipped('integration suite requires the Nextcloud container');
		}
		$this->bridge = \OCP\Server::get(ExpenseClaimsBridge::class);
		$this->db = \OCP\Server::get(IDBConnection::class);
		if (!$this->bridge->capabilityPresent()) {
			self::markTestSkipped('expensecheck capability absent on this instance');
		}
		$this->uid = 'snk-it-' . bin2hex(random_bytes(4));
		$cat = $this->db->executeQuery(
			'SELECT id, requires_attendees FROM *PREFIX*exp_categories ORDER BY id'
		)->fetchAll();
		foreach ($cat as $row) {
			if ((int)$row['requires_attendees'] === 0 && $this->categoryId === 0) {
				$this->categoryId = (int)$row['id'];
			}
			if ((int)$row['requires_attendees'] === 1 && $this->attendeeCategoryId === 0) {
				$this->attendeeCategoryId = (int)$row['id'];
			}
		}
		$cc = $this->db->executeQuery('SELECT id FROM *PREFIX*exp_cost_centers ORDER BY id')->fetchOne();
		$this->costCenterId = $cc === false ? 0 : (int)$cc;
		if ($this->categoryId === 0 || $this->costCenterId === 0) {
			self::markTestSkipped('expensecheck has no usable category/cost-center fixture');
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->createdClaims as $id) {
			$this->db->executeStatement('DELETE FROM *PREFIX*exp_claim_allocations WHERE line_id IN (SELECT id FROM *PREFIX*exp_claim_lines WHERE claim_id = ?)', [$id]);
			$this->db->executeStatement('DELETE FROM *PREFIX*exp_claim_lines WHERE claim_id = ?', [$id]);
			$this->db->executeStatement('DELETE FROM *PREFIX*exp_claims WHERE id = ?', [$id]);
		}
		$this->createdClaims = [];
		parent::tearDown();
	}

	public function testCapabilityAndRoutingReadiness(): void
	{
		self::assertTrue($this->bridge->capabilityPresent());
		self::assertTrue($this->bridge->mappingConfigured($this->categoryId, $this->costCenterId));
		self::assertTrue($this->bridge->routingReady($this->categoryId, $this->costCenterId));
	}

	public function testMappingRejectsUnknownAndAttendeeCategories(): void
	{
		self::assertFalse($this->bridge->mappingConfigured(0, 0));
		self::assertFalse($this->bridge->mappingConfigured(999999999, $this->costCenterId));
		self::assertFalse($this->bridge->mappingConfigured($this->categoryId, 999999999));
		if ($this->attendeeCategoryId > 0) {
			// Bewirtung-class categories legally need attendees+occasion — SNK
			// never collects them, so such a mapping must read as unconfigured.
			self::assertFalse($this->bridge->mappingConfigured($this->attendeeCategoryId, $this->costCenterId));
		}
		self::assertFalse($this->bridge->routingReady($this->categoryId, 0));
	}

	public function testSubmitDraftCreatesDraftWithProvenanceLine(): void
	{
		$ref = 'snkexp:it-' . bin2hex(random_bytes(6));
		$res = $this->bridge->submitDraft($this->uid, 1234, 'Milk for the kitchen', $ref, $this->categoryId, $this->costCenterId);

		self::assertNotSame('', $res['ref']);
		self::assertFalse($res['deduplicated']);

		$claim = $this->db->executeQuery(
			'SELECT * FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetch();
		self::assertNotFalse($claim);
		$this->createdClaims[] = (int)$claim['id'];
		self::assertSame('draft', $claim['status']);
		self::assertSame($this->uid, $claim['user_id']);
		self::assertSame(1234, (int)$claim['total_minor']);

		$line = $this->db->executeQuery(
			'SELECT * FROM *PREFIX*exp_claim_lines WHERE claim_id = ?',
			[(int)$claim['id']]
		)->fetch();
		self::assertNotFalse($line);
		self::assertSame('receipt', $line['line_type']);
		self::assertSame(1234, (int)$line['amount_minor']);
		self::assertSame(ExpenseClaimsBridge::SOURCE_APP, $line['source_app']);
		self::assertSame($ref, $line['source_ref']);
		self::assertSame($this->categoryId, (int)$line['category_id']);

		$alloc = $this->db->executeQuery(
			'SELECT * FROM *PREFIX*exp_claim_allocations WHERE line_id = ?',
			[(int)$line['id']]
		)->fetch();
		self::assertNotFalse($alloc);
		self::assertSame($this->costCenterId, (int)$alloc['cost_center_id']);
		self::assertSame(1234, (int)$alloc['amount_minor']);

		// Role-scoped url contract: `url` exists exactly when the acting uid
		// passes EXP's access door AND may view the claim — the 'Open claim'
		// action must never be a dead end for a role that gets 403.
		self::assertSame(
			$this->expCanOpenClaim((int)$claim['id'], $this->uid),
			isset($res['url']),
			'url must be emitted iff EXP door + claim visibility allow the actor',
		);
		if (isset($res['url'])) {
			self::assertStringContainsString('/apps/expensecheck/claims/', $res['url']);
		}
	}

	/**
	 * EXP-side contract mirror: can the uid actually open the claim page?
	 * Door (canUseApp) AND claim visibility (canView) — the same two gates
	 * AppAccessMiddleware + PageController::claimDetail enforce.
	 */
	private function expCanOpenClaim(int $claimId, string $uid): bool
	{
		$access = \OCP\Server::get(\OCA\ExpenseCheck\Service\AccessControlService::class);
		if (!$access->canUseApp($uid)) {
			return false;
		}
		$claim = \OCP\Server::get(\OCA\ExpenseCheck\Db\ClaimMapper::class)->findByIdRaw($claimId);
		if ($claim === null) {
			return false;
		}
		return \OCP\Server::get(\OCA\ExpenseCheck\Service\ClaimLifecycleService::class)->canView($claim, $uid);
	}

	/**
	 * A member (or any non-owner, non-privileged actor) who cannot open the
	 * EXP claim page must get the ref WITHOUT the url — the web toast then
	 * shows the honest 'Expense claim submitted' text with no dead CTA.
	 * Deterministic: a synthetic actor uid is never owner/app-admin/finance/
	 * auditor/decision-rights holder, and fails the door whenever EXP's
	 * access restriction is enabled.
	 */
	public function testSubmitDraftOmitsUrlForIneligibleActor(): void
	{
		$ref = 'snkexp:it-' . bin2hex(random_bytes(6));
		$foreignActor = 'snk-it-other-' . bin2hex(random_bytes(4));
		$res = $this->bridge->submitDraft(
			$this->uid, 640, 'Printer paper', $ref, $this->categoryId, $this->costCenterId, $foreignActor,
		);
		self::assertNotSame('', $res['ref']);
		self::assertFalse($res['deduplicated']);
		self::assertArrayNotHasKey('url', $res, 'ineligible actor must not receive a dead claim url');

		$claim = $this->db->executeQuery(
			'SELECT id FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetch();
		self::assertNotFalse($claim);
		$this->createdClaims[] = (int)$claim['id'];
		self::assertFalse($this->expCanOpenClaim((int)$claim['id'], $foreignActor));
	}

	/**
	 * Regression leg for page-reachable roles: an actor EXP's door admits
	 * AND who may view the claim still receives the deep link. Resolved
	 * dynamically — any Nextcloud admin qualifies (sysadmin passes the door
	 * and is always an EXP app admin).
	 */
	public function testSubmitDraftEmitsUrlForEligibleActor(): void
	{
		$actor = $this->eligibleExpActor();
		if ($actor === null) {
			self::markTestSkipped('no EXP-eligible actor resolvable on this instance');
		}
		$ref = 'snkexp:it-' . bin2hex(random_bytes(6));
		$res = $this->bridge->submitDraft(
			$this->uid, 730, 'Dish soap', $ref, $this->categoryId, $this->costCenterId, $actor,
		);
		self::assertNotSame('', $res['ref']);
		self::assertArrayHasKey('url', $res);
		self::assertStringContainsString('/apps/expensecheck/claims/', $res['url']);

		$claim = $this->db->executeQuery(
			'SELECT id FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetch();
		self::assertNotFalse($claim);
		$this->createdClaims[] = (int)$claim['id'];
		self::assertTrue($this->expCanOpenClaim((int)$claim['id'], $actor));
	}

	/** Any NC admin passes EXP's door (isSystemAdmin→isAppAdmin) + canView. */
	private function eligibleExpActor(): ?string
	{
		$access = \OCP\Server::get(\OCA\ExpenseCheck\Service\AccessControlService::class);
		foreach (['admin', 'exp_doc_emp', 'exp.tester', 'expe2eweb', 'exp_doc'] as $uid) {
			if ($access->canUseApp($uid) && $access->isAppAdmin($uid)) {
				return $uid;
			}
		}
		return null;
	}

	public function testSubmitDraftDedupesOnRequestKey(): void
	{
		$ref = 'snkexp:it-' . bin2hex(random_bytes(6));
		$first = $this->bridge->submitDraft($this->uid, 500, 'Coffee filters', $ref, $this->categoryId, $this->costCenterId);
		$claimRow = $this->db->executeQuery(
			'SELECT id FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetch();
		self::assertNotFalse($claimRow);
		$this->createdClaims[] = (int)$claimRow['id'];

		// A resubmit (double-click / retry) with the same idempotency-derived
		// source_ref MUST collapse onto the same draft — never a second claim.
		$second = $this->bridge->submitDraft($this->uid, 500, 'Coffee filters', $ref, $this->categoryId, $this->costCenterId);
		self::assertTrue($second['deduplicated']);
		self::assertSame($first['ref'], $second['ref']);

		$count = (int)$this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetchOne();
		self::assertSame(1, $count);
		$lines = (int)$this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*exp_claim_lines WHERE claim_id = ?',
			[(int)$claimRow['id']]
		)->fetchOne();
		self::assertSame(1, $lines);
	}

	public function testSubmitDraftRejectsSameKeyWithDifferentPayload(): void
	{
		$ref = 'snkexp:it-' . bin2hex(random_bytes(6));
		$first = $this->bridge->submitDraft($this->uid, 500, 'Coffee filters', $ref, $this->categoryId, $this->costCenterId);
		$claimRow = $this->db->executeQuery(
			'SELECT id FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetch();
		$this->createdClaims[] = (int)$claimRow['id'];

		// Same idempotency key, DIFFERENT amount → idempotency_conflict 409,
		// identical to the ledger path's canon-hash semantics — a key must
		// never silently bind a different money value.
		try {
			$this->bridge->submitDraft($this->uid, 999, 'Coffee filters', $ref, $this->categoryId, $this->costCenterId);
			self::fail('replayed key with a different amount must conflict');
		} catch (DomainException $e) {
			self::assertSame('idempotency_conflict', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
		}
		// …and different reason likewise.
		try {
			$this->bridge->submitDraft($this->uid, 500, 'Different reason', $ref, $this->categoryId, $this->costCenterId);
			self::fail('replayed key with a different reason must conflict');
		} catch (DomainException $e) {
			self::assertSame('idempotency_conflict', $e->errorCode);
		}
		// The original draft is untouched.
		self::assertSame(500, (int)$this->db->executeQuery(
			'SELECT total_minor FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetchOne());
	}

	public function testSubmitDraftRejectsStaleMappingAsUnavailable(): void
	{
		$ref = 'snkexp:it-' . bin2hex(random_bytes(6));
		try {
			$this->bridge->submitDraft($this->uid, 100, 'x', $ref, 999999999, $this->costCenterId);
			self::fail('stale category must not silently create a claim');
		} catch (DomainException $e) {
			// EXP's ValidationException collapses onto the SNK contract surface.
			self::assertSame('validation_failed', $e->errorCode);
			self::assertSame(422, $e->httpStatus);
		}
		// Partial-failure compensation: createDraft commits before addLine
		// throws — the orphan claim + its chain event must be gone, not left
		// as an empty draft in the member's EXP inbox.
		$claims = (int)$this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*exp_claims WHERE request_key = ?',
			[$this->uid . ':ingress:' . $ref]
		)->fetchOne();
		self::assertSame(0, $claims);
		$events = (int)$this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*exp_claim_events e WHERE e.actor = ? AND NOT EXISTS (SELECT 1 FROM *PREFIX*exp_claims c WHERE c.id = e.claim_id)',
			[$this->uid]
		)->fetchOne();
		self::assertSame(0, $events, 'orphan chain event left by failed draft compensation');
	}
}
