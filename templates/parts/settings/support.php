<?php
/**
 * Settings · Help & Support (Support & us + kitchen tablet shortlist).
 *
 * Informational CTAs only — never gates AGPL web use. SupportUsLinks is built
 * by PageController::settings() and passed via $_.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */

$supportUsLinks = $_['supportUsLinks'] ?? null;
if (!$supportUsLinks instanceof \OCA\SnackCheck\Support\SupportUsLinks) {
	$licenseUrl = $urlGenerator->linkToRouteAbsolute('snackcheck.page.settings', ['section' => 'license']) . '#snk-license-key';
	$supportUsLinks = new \OCA\SnackCheck\Support\SupportUsLinks('SnackCheck', true, $licenseUrl);
}

$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$supportUsCssPrefix = 'snk';
$supportUsShellPrefix = 'snk';
$supportUsBtnPrimaryClass = 'snk-btn snk-btn--primary';
$supportUsBtnSecondaryClass = 'snk-btn';
$supportUsPresentation = 'embed';

include __DIR__ . '/../support-us-section.php';

$shortlistHref = $urlGenerator->linkTo('snackcheck', 'public/docs/DEVICE-SHORTLIST.md');
?>
<section class="snk-support-extra" aria-labelledby="snk-device-shortlist-title">
	<h3 id="snk-device-shortlist-title" class="snk-support-extra__title"><?php p($l->t('Kitchen tablet')); ?></h3>
	<p class="snk-muted"><?php p($l->t('Recommended tablets for the kitchen app (SNK2).')); ?></p>
	<p>
		<a class="snk-btn"
		   href="<?php p($shortlistHref); ?>"
		   rel="noopener noreferrer"
		   target="_blank">
			<?php p($l->t('Recommended kitchen tablets')); ?>
			<span class="snk-sr-only"><?php p($l->t('(opens in a new tab)')); ?></span>
		</a>
	</p>
</section>
