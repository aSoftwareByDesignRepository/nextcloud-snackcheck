<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\PeriodMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class PeriodService
{
	public function __construct(
		private readonly PeriodMapper $mapper,
		private readonly \OCA\SnackCheck\Db\ConsumptionLogMapper $logs,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function findOpen(): ?Period
	{
		return $this->mapper->findOpen();
	}

	public function findLatestClosed(): ?Period
	{
		return $this->mapper->findLatestClosed();
	}

	/**
	 * Cold-start only (AS-02): create current calendar month when the ledger has never had a period.
	 * After a close, does NOT invent a successor — that would defeat AC-16 write lock.
	 * Admins open the next period explicitly via openNextPeriod().
	 */
	public function ensureOpenPeriod(): Period
	{
		$this->db->beginTransaction();
		try {
			$this->mapper->lockOpenPeriodGate();
			$open = $this->mapper->lockOpen();
			if ($open !== null) {
				$this->db->commit();
				return $open;
			}
			if ($this->mapper->countAll() > 0) {
				throw new DomainException('period_closed', 'No open period — open the next period from Periods', 409);
			}
			$period = $this->insertLabeledOpenPeriod($this->timeFactory->getDateTime());
			$this->db->commit();
			return $period;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Write path: require an open period. Never auto-creates (AC-16 / AC-3 / AC-M3).
	 */
	public function getOpenOrFail(): Period
	{
		$open = $this->mapper->findOpen();
		if ($open === null) {
			throw new DomainException('period_closed', 'No open period', 409);
		}
		return $open;
	}

	/**
	 * Admin action after close: open the next calendar-month period (successor labels if needed).
	 */
	public function openNextPeriod(string $actorUid): Period
	{
		$this->db->beginTransaction();
		try {
			$this->mapper->lockOpenPeriodGate();
			$existing = $this->mapper->lockOpen();
			if ($existing !== null) {
				throw new DomainException('period_open_exists', 'An open period already exists', 409);
			}
			$period = $this->insertLabeledOpenPeriod($this->timeFactory->getDateTime());
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->record($actorUid, 'period.open', 'period', (string)$period->getId(), [
			'label' => $period->getLabel(),
		]);
		return $period;
	}

	private function insertLabeledOpenPeriod(\DateTimeInterface $now): Period
	{
		$baseLabel = $now->format('Y-m');
		$label = $baseLabel;
		$suffix = 2;
		while (($existing = $this->mapper->findByLabel($label)) !== null) {
			if ($existing->getState() === 'open') {
				return $existing;
			}
			$label = $baseLabel . '-' . $suffix;
			$suffix++;
			if ($suffix > 40) {
				throw new DomainException('period_open_exists', 'Cannot open period', 409);
			}
		}
		$start = new \DateTimeImmutable($now->format('Y-m-d'));
		$end = new \DateTimeImmutable($now->format('Y-m-t'));
		$period = new Period();
		$period->setLabel($label);
		$period->setStartsOn(new \DateTime($start->format('Y-m-d')));
		$period->setEndsOn(new \DateTime($end->format('Y-m-d')));
		$period->setState('open');
		$period->setOpenGuard(1);
		$period->setCreatedAt($now instanceof \DateTime ? $now : \DateTime::createFromInterface($now));
		return $this->mapper->insert($period);
	}

	/**
	 * @return array{period: Period, warnings: list<string>}
	 */
	public function close(int $periodId, string $actorUid, bool $confirmWarnings = false): array
	{
		$this->db->beginTransaction();
		try {
			$this->mapper->lockOpenPeriodGate();
			$period = $this->mapper->lockRow($periodId);
			if ($period === null) {
				throw new DomainException('not_found', 'Period not found', 404);
			}
			if ($period->getState() !== 'open') {
				throw new DomainException('period_closed', 'Period already closed', 409);
			}
			$other = $this->mapper->findOpen();
			if ($other !== null && (int)$other->getId() !== (int)$period->getId()) {
				throw new DomainException('period_open_exists', 'Another open period exists', 409);
			}

			$warnings = [];
			$logCount = $this->logs->countNonVoidedForPeriod((int)$period->getId());
			if ($logCount === 0) {
				$warnings[] = 'zero_logs';
			}
			$prev = $this->mapper->findPreviousClosed((int)$period->getId());
			if ($prev !== null) {
				$prevCount = $this->logs->countNonVoidedForPeriod((int)$prev->getId());
				if ($prevCount > 0) {
					$delta = abs($logCount - $prevCount) / max(1, $prevCount);
					if ($delta >= 0.5) {
						$warnings[] = 'huge_mom_delta';
					}
				}
			}
			if ($warnings !== [] && !$confirmWarnings) {
				$this->db->rollBack();
				return ['period' => $period, 'warnings' => $warnings];
			}

			$period->setState('closed');
			$period->setOpenGuard(null);
			$period->setClosedAt($this->timeFactory->getDateTime());
			$period->setClosedBy($actorUid);
			$this->mapper->update($period);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->audit->record($actorUid, 'period.close', 'period', (string)$periodId);
		return ['period' => $period, 'warnings' => []];
	}

	public function reopen(int $periodId, string $actorUid, string $reason): Period
	{
		$reason = trim($reason);
		if (mb_strlen($reason) < 3) {
			throw new DomainException('validation_failed', 'Reopen reason required', 422);
		}
		if (mb_strlen($reason) > 500) {
			throw new DomainException('validation_failed', 'Reopen reason too long', 422);
		}
		$this->db->beginTransaction();
		try {
			$this->mapper->lockOpenPeriodGate();
			$existingOpen = $this->mapper->lockOpen();
			if ($existingOpen !== null) {
				throw new DomainException('period_open_exists', 'An open period already exists', 409);
			}
			$period = $this->mapper->lockRow($periodId);
			if ($period === null) {
				throw new DomainException('not_found', 'Period not found', 404);
			}
			$period->setState('open');
			$period->setOpenGuard(1);
			$period->setReopenReason($reason);
			$period->setClosedAt(null);
			$period->setClosedBy(null);
			// Fresh handoff required after any reopen (settlement status must not lie).
			$period->setHandedToHrAt(null);
			$period->setHandedToHrBy(null);
			$this->mapper->update($period);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->record($actorUid, 'period.reopen', 'period', (string)$periodId, ['reason' => $reason]);
		return $period;
	}

	public function markHandedToHr(int $periodId, string $actorUid): Period
	{
		$this->db->beginTransaction();
		try {
			$period = $this->mapper->lockRow($periodId);
			if ($period === null) {
				throw new DomainException('not_found', 'Period not found', 404);
			}
			if ($period->getState() !== 'closed') {
				throw new DomainException('period_open', 'Hand to HR only after period close', 409);
			}
			$period->setHandedToHrAt($this->timeFactory->getDateTime());
			$period->setHandedToHrBy($actorUid);
			$this->mapper->update($period);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->record($actorUid, 'period.handed_to_hr', 'period', (string)$periodId);
		return $period;
	}

	/** @return list<Period> */
	public function listAll(): array
	{
		return $this->mapper->findAllOrdered();
	}

	public function get(int $id): Period
	{
		$p = $this->mapper->find($id);
		if ($p === null) {
			throw new DomainException('not_found', 'Period not found', 404);
		}
		return $p;
	}
}
