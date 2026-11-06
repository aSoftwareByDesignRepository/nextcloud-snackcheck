<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\SiteMapper;
use OCA\SnackCheck\Db\TerminalDeviceMapper;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * snk_sites_code_uq race on first-use ensureDefaultSite(): the losing caller
 * must converge to the winner row, never surface the raw duplicate-key 500
 * (unique_index_write_no_conflict_path).
 */
final class SiteEnsureDefaultUniqueRaceTest extends TestCase
{
	private function service(SiteMapper $mapper): SiteService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-06T20:40:00Z'));
		return new SiteService(
			$mapper,
			$this->createMock(SettingsService::class),
			$this->createMock(CatalogItemMapper::class),
			$this->createMock(TerminalDeviceMapper::class),
			$this->createMock(TerminalDeviceService::class),
			$time,
			$this->createMock(ILockingProvider::class),
		);
	}

	private function uniqueViolation(): DbException
	{
		return new class ('duplicate key value violates unique constraint "snk_sites_code_uq"') extends DbException {
			public function getReason(): ?int
			{
				return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	public function testUniqueRaceConvergesToWinner(): void
	{
		$winner = new Site();
		$winner->setId(7);
		$winner->setCode(SiteService::DEFAULT_CODE);
		$winner->setName(SiteService::DEFAULT_NAME);
		$winner->setActive(1);
		$winner->setManagersJson('[]');

		$mapper = $this->createMock(SiteMapper::class);
		// Loser path: first find misses, insert hits the unique index, re-read
		// returns the winner committed by the concurrent caller.
		$mapper->expects($this->exactly(2))
			->method('findByCode')
			->with(SiteService::DEFAULT_CODE)
			->willReturnOnConsecutiveCalls(null, $winner);
		$mapper->expects($this->once())
			->method('insert')
			->willThrowException($this->uniqueViolation());

		$svc = $this->service($mapper);
		self::assertSame($winner, $svc->ensureDefaultSite());
	}

	public function testUniqueRaceRethrowsWhenWinnerNeverMaterialises(): void
	{
		$mapper = $this->createMock(SiteMapper::class);
		$mapper->expects($this->exactly(2))
			->method('findByCode')
			->willReturnOnConsecutiveCalls(null, null);
		$mapper->expects($this->once())
			->method('insert')
			->willThrowException($this->uniqueViolation());

		$svc = $this->service($mapper);
		$this->expectException(DbException::class);
		$svc->ensureDefaultSite();
	}

	public function testNonUniqueDbExceptionPropagatesUnwrapped(): void
	{
		$other = new class ('deadlock found') extends DbException {
			public function getReason(): ?int
			{
				return DbException::REASON_DEADLOCK;
			}
		};

		$mapper = $this->createMock(SiteMapper::class);
		$mapper->expects($this->once())
			->method('findByCode')
			->willReturn(null);
		$mapper->expects($this->once())
			->method('insert')
			->willThrowException($other);

		$svc = $this->service($mapper);
		try {
			$svc->ensureDefaultSite();
			self::fail('expected DbException');
		} catch (DbException $e) {
			self::assertSame($other, $e);
		}
	}

	public function testExistingDefaultReturnsWithoutInsert(): void
	{
		$existing = new Site();
		$existing->setId(3);
		$existing->setCode(SiteService::DEFAULT_CODE);

		$mapper = $this->createMock(SiteMapper::class);
		$mapper->expects($this->once())
			->method('findByCode')
			->willReturn($existing);
		$mapper->expects($this->never())->method('insert');

		$svc = $this->service($mapper);
		self::assertSame($existing, $svc->ensureDefaultSite());
	}
}
