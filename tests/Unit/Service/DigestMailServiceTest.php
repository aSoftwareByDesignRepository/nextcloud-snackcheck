<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Service\DigestMailService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\PulseService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\SubsidyService;
use OCA\SnackCheck\Db\Site;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DigestMailServiceTest extends TestCase
{
	public function testSkipsWhenOutsideWindow(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isPersonalDigestEnabled')->willReturn(true);
		$period = new Period();
		$period->setId(1);
		$period->setLabel('2026-08');
		$period->setEndsOn(new \DateTime('2026-08-31'));
		$periods = $this->createMock(PeriodService::class);
		$periods->method('ensureOpenPeriod')->willReturn($period);
		$periods->method('findOpen')->willReturn($period);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $def = '') {
			if ($key === 'personal_digest_days_before') {
				return '3';
			}
			return $def;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));
		$svc = new DigestMailService(
			$settings,
			$periods,
			$this->createMock(SiteService::class),
			$this->createMock(PulseService::class),
			new SubsidyService(),
			$this->createMock(ConsumptionLogMapper::class),
			$this->createMock(IMailer::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IURLGenerator::class),
			$config,
			$time,
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
		$result = $svc->sendPersonalDigests(new \DateTime('2026-08-10'));
		self::assertFalse($result['eligible']);
		self::assertSame(0, $result['sent']);
	}

	public function testSendsAndClaimsIdempotently(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isPersonalDigestEnabled')->willReturn(true);
		$settings->method('isPersonalDigestSkipZeroEnabled')->willReturn(false);
		$settings->method('getSubsidyAllowanceCents')->willReturn(0);
		$period = new Period();
		$period->setId(2);
		$period->setLabel('2026-08');
		$period->setEndsOn(new \DateTime('2026-08-12'));
		$periods = $this->createMock(PeriodService::class);
		$periods->method('ensureOpenPeriod')->willReturn($period);
		$periods->method('findOpen')->willReturn($period);
		$log = new \OCA\SnackCheck\Db\ConsumptionLog();
		$log->setUserId('alice');
		$log->setLineTotalCents(80);
		$log->setBillingBucket('personal');
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->willReturn([$log]);
		$mapper->method('findForUserPeriod')->willReturn([$log]);
		$claims = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $def = '') use (&$claims) {
			if ($key === 'personal_digest_days_before') {
				return '3';
			}
			return $claims[$key] ?? $def;
		});
		$config->method('setAppValue')->willReturnCallback(static function ($app, $key, $val) use (&$claims) {
			$claims[$key] = $val;
		});
		$message = $this->createMock(IMessage::class);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('a@example.com');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('alice')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($t, $a = []) => $t);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/mymonth');
		$time = $this->createMock(ITimeFactory::class);
		$svc = new DigestMailService(
			$settings,
			$periods,
			$this->createMock(SiteService::class),
			$this->createMock(PulseService::class),
			new SubsidyService(),
			$mapper,
			$mailer,
			$users,
			$url,
			$config,
			$time,
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
		$first = $svc->sendPersonalDigests(new \DateTime('2026-08-10'));
		self::assertTrue($first['eligible']);
		self::assertSame(1, $first['sent']);
		$second = $svc->sendPersonalDigests(new \DateTime('2026-08-10'));
		self::assertSame(0, $second['sent']);
		self::assertSame(1, $second['skipped']);
	}

	public function testSkipsZeroDeductWithoutClaiming(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isPersonalDigestEnabled')->willReturn(true);
		$settings->method('isPersonalDigestSkipZeroEnabled')->willReturn(true);
		$settings->method('getSubsidyAllowanceCents')->willReturn(10000); // full subsidy ⇒ deduct 0
		$period = new Period();
		$period->setId(3);
		$period->setLabel('2026-08');
		$period->setEndsOn(new \DateTime('2026-08-12'));
		$periods = $this->createMock(PeriodService::class);
		$periods->method('findOpen')->willReturn($period);
		$log = new \OCA\SnackCheck\Db\ConsumptionLog();
		$log->setUserId('bob');
		$log->setLineTotalCents(80);
		$log->setBillingBucket('personal');
		$mapper = $this->createMock(ConsumptionLogMapper::class);
		$mapper->method('findForPeriod')->willReturn([$log]);
		$mapper->method('findForUserPeriod')->willReturn([$log]);
		$claims = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $def = '') use (&$claims) {
			if ($key === 'personal_digest_days_before') {
				return '3';
			}
			return $claims[$key] ?? $def;
		});
		$config->method('setAppValue')->willReturnCallback(static function ($app, $key, $val) use (&$claims) {
			$claims[$key] = $val;
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects(self::never())->method('send');
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('b@example.com');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('bob')->willReturn($user);
		$svc = new DigestMailService(
			$settings,
			$periods,
			$this->createMock(SiteService::class),
			$this->createMock(PulseService::class),
			new SubsidyService(),
			$mapper,
			$mailer,
			$users,
			$this->createMock(IURLGenerator::class),
			$config,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
		$result = $svc->sendPersonalDigests(new \DateTime('2026-08-10'));
		self::assertTrue($result['eligible']);
		self::assertSame(0, $result['sent']);
		self::assertSame(1, $result['skipped']);
		self::assertSame([], $claims, 'skip-€0 must not claim idempotency key');
	}

	public function testWeeklyDisabledReturnsZero(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isWeeklyTopUpEmailEnabled')->willReturn(false);
		$svc = new DigestMailService(
			$settings,
			$this->createMock(PeriodService::class),
			$this->createMock(SiteService::class),
			$this->createMock(PulseService::class),
			new SubsidyService(),
			$this->createMock(ConsumptionLogMapper::class),
			$this->createMock(IMailer::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);
		self::assertSame(['sent' => 0, 'items' => 0], $svc->sendWeeklyTopUp());
	}

	public function testWeeklyIncludesSiteManagersAcrossSites(): void
	{
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isWeeklyTopUpEmailEnabled')->willReturn(true);
		$settings->method('isMultiSiteEnabled')->willReturn(true);
		$settings->method('getAppAdmins')->willReturn(['orgadmin']);

		$berlin = new Site();
		$berlin->setId(1);
		$berlin->setName('Berlin');
		$berlin->setManagersJson(json_encode(['berlinmgr']));
		$munich = new Site();
		$munich->setId(2);
		$munich->setName('Munich');
		$munich->setManagersJson(json_encode(['munichmgr']));

		$sites = $this->createMock(SiteService::class);
		$sites->method('listActive')->willReturn([$berlin, $munich]);
		$sites->method('managerUids')->willReturnCallback(static function (Site $s) {
			$raw = $s->getManagersJson();
			return $raw ? (json_decode($raw, true) ?: []) : [];
		});

		$pulse = $this->createMock(PulseService::class);
		$pulse->method('buildForSite')->willReturnCallback(static function (int $siteId) {
			$name = $siteId === 1 ? 'BerlinMilch' : 'MunichMilch';
			return ['topUp' => [
				['name' => $name, 'suggestedBuy' => 2, 'onHand' => 1, 'parLevel' => 3],
			]];
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$claimed = [];
		$config->method('setAppValue')->willReturnCallback(static function ($app, $key, $val) use (&$claimed) {
			$claimed[$key] = $val;
		});

		$bodies = [];
		$message = $this->createMock(IMessage::class);
		$message->method('setPlainBody')->willReturnCallback(static function ($body) use (&$bodies, $message) {
			$bodies[] = $body;
			return $message;
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);

		$userMap = [];
		foreach (['orgadmin' => 'a@ex.com', 'berlinmgr' => 'b@ex.com', 'munichmgr' => 'm@ex.com'] as $uid => $email) {
			$u = $this->createMock(IUser::class);
			$u->method('getEMailAddress')->willReturn($email);
			$userMap[$uid] = $u;
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid) => $userMap[$uid] ?? null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($t, $a = []) => is_array($a) && $a !== [] ? ($t . ' ' . implode(',', $a)) : $t);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/pulse');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));

		$svc = new DigestMailService(
			$settings,
			$this->createMock(PeriodService::class),
			$sites,
			$pulse,
			new SubsidyService(),
			$this->createMock(ConsumptionLogMapper::class),
			$mailer,
			$users,
			$url,
			$config,
			$time,
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCP\Lock\ILockingProvider::class),
		);

		$result = $svc->sendWeeklyTopUp(new \DateTime('2026-08-10'));
		self::assertSame(3, $result['sent']);
		self::assertSame(2, $result['items']);
		self::assertCount(3, $bodies);
		// Org admin sees both sites; site managers must not see foreign stock (AC-OPP-Y5).
		$adminBody = $bodies[0];
		self::assertStringContainsString('BerlinMilch', $adminBody);
		self::assertStringContainsString('MunichMilch', $adminBody);
		$berlinBody = $bodies[1];
		self::assertStringContainsString('BerlinMilch', $berlinBody);
		self::assertStringNotContainsString('MunichMilch', $berlinBody);
		$munichBody = $bodies[2];
		self::assertStringContainsString('MunichMilch', $munichBody);
		self::assertStringNotContainsString('BerlinMilch', $munichBody);
		self::assertNotEmpty($claimed);
	}
}
