<?php
/**
 * App settings shell — one topic per URL (Check-family / SETTINGS-PAGES-STANDARD).
 *
 * Controller validates `section` against SettingsSectionCatalog; this template
 * dispatches through a literal slug → file map (never request-built includes).
 * Page H1 + lead live in main.php chrome; this shell is nav chips + section body.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */

$snkRequestedSection = (string)($_['section'] ?? '');
$snkSettingsSectionFiles = [
	'access' => 'access.php',
	'benefits' => 'benefits.php',
	'privacy' => 'privacy.php',
	'pulse' => 'pulse.php',
	'digests' => 'digests.php',
	'unlock' => 'unlock.php',
	'license' => 'license.php',
	'support' => 'support.php',
];
?>
<section class="snk-section snk-section--settings" aria-label="<?php p($l->t('Settings')); ?>">
	<?php include __DIR__ . '/../parts/settings-nav.php'; ?>

	<article class="snk-card snk-settings-panel">
		<div class="snk-card__body snk-settings-panel__body">
			<?php
			if (!isset($snkSettingsSectionFiles[$snkRequestedSection])) {
				throw new \RuntimeException('SnackCheck settings: unknown section reached the template dispatcher.');
			}
			include __DIR__ . '/../parts/settings/' . $snkSettingsSectionFiles[$snkRequestedSection];
			?>
		</div>
	</article>
</section>
