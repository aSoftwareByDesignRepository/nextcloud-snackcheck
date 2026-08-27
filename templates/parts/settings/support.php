<?php
/**
 * Settings · support
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<?php
		$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
		$supportUsCssPrefix = 'snk';
		$supportUsShellPrefix = 'snk';
		$supportUsBtnPrimaryClass = 'snk-btn snk-btn--primary';
		$supportUsBtnSecondaryClass = 'snk-btn';
		$supportUsPresentation = 'embed';
		$licenseUrl = $urlGenerator->linkToRouteAbsolute('snackcheck.page.settings', ['section' => 'license']) . '#snk-license-key';
		$supportUsLinks = new \OCA\SnackCheck\Support\SupportUsLinks('SnackCheck', true, $licenseUrl);
		include __DIR__ . '/../support-us-section.php';
		?>
		<p>
			<a class="snk-btn" href="<?php p($urlGenerator->linkTo('snackcheck', 'docs/DEVICE-SHORTLIST.md')); ?>" rel="noopener noreferrer" target="_blank">
				<?php p($l->t('Kitchen tablet device shortlist')); ?>
			</a>
		</p>
	<?php endif; ?>
