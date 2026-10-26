<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Coverage;

use OCA\SnackCheck\Cron\PersonalDigestJob;
use OCA\SnackCheck\Cron\WeeklyTopUpJob;
use OCA\SnackCheck\Service\DigestMailService;
use OCA\SnackCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Atlas v3 — invoke TimedJob run() entrypoints.
 */
final class AtlasJobsInvokeCoverageTest extends TestCase
{
	private function invokeRun(object $job, mixed $argument = null): void
	{
		$method = new ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	/**
	 * Lock contract (restore_lock_contract_half_wired): both jobs take a
	 * SHARED lock on UpgradeBackupService::LOCK_KEY so a mid-restore
	 * truncate+reinsert cannot interleave with their app-table scans,
	 * and release it in finally.
	 */
	private function expectSharedLockCycle(MockObject $locking): void
	{
		$locking->expects($this->once())
			->method('acquireLock')
			->with(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
			);
		$locking->expects($this->once())
			->method('releaseLock')
			->with(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
			);
	}

	public function testPersonalDigestJobRun(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->expects($this->once())->method('sendPersonalDigests')->willReturn(['sent' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$locking = $this->createMock(ILockingProvider::class);
		$this->expectSharedLockCycle($locking);
		$this->invokeRun(new PersonalDigestJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
			$locking,
		));
	}

	public function testWeeklyTopUpJobRun(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->expects($this->once())->method('sendWeeklyTopUp')->willReturn(['sent' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$locking = $this->createMock(ILockingProvider::class);
		$this->expectSharedLockCycle($locking);
		$this->invokeRun(new WeeklyTopUpJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
			$locking,
		));
	}

	/**
	 * Lock held exclusively by a backup/restore → job skips its work
	 * (no digest call) and logs a warning; it must NOT release a lock
	 * it never acquired.
	 */
	public function testPersonalDigestJobSkipsWhenRestoreHoldsLock(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->expects($this->never())->method('sendPersonalDigests');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())
			->method('acquireLock')
			->willThrowException(new LockedException(UpgradeBackupService::LOCK_KEY));
		$locking->expects($this->never())->method('releaseLock');
		$this->invokeRun(new PersonalDigestJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
			$locking,
		));
	}

	public function testWeeklyTopUpJobSkipsWhenRestoreHoldsLock(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->expects($this->never())->method('sendWeeklyTopUp');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())
			->method('acquireLock')
			->willThrowException(new LockedException(UpgradeBackupService::LOCK_KEY));
		$locking->expects($this->never())->method('releaseLock');
		$this->invokeRun(new WeeklyTopUpJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
			$locking,
		));
	}

	/** The shared lock is released even when the digest body throws. */
	public function testLockReleasedWhenDigestThrows(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->method('sendPersonalDigests')->willThrowException(new \RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$locking = $this->createMock(ILockingProvider::class);
		$this->expectSharedLockCycle($locking);
		$this->invokeRun(new PersonalDigestJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
			$locking,
		));
	}

	public function testBothRegisteredJobsConstructAndRun(): void
	{
		$ran = [];
		foreach ([PersonalDigestJob::class, WeeklyTopUpJob::class] as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			self::assertNotNull($ctor);
			$args = [];
			foreach ($ctor->getParameters() as $param) {
				$type = $param->getType();
				self::assertInstanceOf(\ReflectionNamedType::class, $type);
				$mock = $this->createMock($type->getName());
				if ($type->getName() === DigestMailService::class) {
					$mock->method('sendPersonalDigests')->willReturn(['sent' => 0]);
					$mock->method('sendWeeklyTopUp')->willReturn(['sent' => 0]);
				}
				$args[] = $mock;
			}
			$job = $ref->newInstanceArgs($args);
			$this->invokeRun($job);
			$ran[] = $ref->getShortName() . '::run';
		}
		self::assertSame([
			'PersonalDigestJob::run',
			'WeeklyTopUpJob::run',
		], $ran);
	}
}
