<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Coverage;

use OCA\SnackCheck\Cron\PersonalDigestJob;
use OCA\SnackCheck\Cron\WeeklyTopUpJob;
use OCA\SnackCheck\Service\DigestMailService;
use OCP\AppFramework\Utility\ITimeFactory;
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

	public function testPersonalDigestJobRun(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->expects($this->once())->method('sendPersonalDigests')->willReturn(['sent' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$this->invokeRun(new PersonalDigestJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
		));
	}

	public function testWeeklyTopUpJobRun(): void
	{
		$digests = $this->createMock(DigestMailService::class);
		$digests->expects($this->once())->method('sendWeeklyTopUp')->willReturn(['sent' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$this->invokeRun(new WeeklyTopUpJob(
			$this->createMock(ITimeFactory::class),
			$digests,
			$logger,
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
