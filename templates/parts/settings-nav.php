<?php
/**
 * In-page settings chip bar (Check-family dual-nav).
 *
 * Labels/URLs come from the controller (SettingsSectionCatalog) — never hardcoded.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $snkRequestedSection
 */

$snkNavLabels = (array)($_['settingsSectionLabels'] ?? []);
$snkNavUrls = (array)(($_['urls']['settingsSections'] ?? []) ?: []);
if ($snkNavLabels === []) {
	return;
}
?>
<nav class="snk-settings-nav" id="snk-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($snkNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string)$sectionId;
		$href = (string)($snkNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = $snkRequestedSection === $sectionId;
		?>
		<a class="snk-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string)$sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>
