<?php
/**
 * SnackCheck page shell — design-system chrome (sidebar + page header).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\SnackCheck\Service\IconCatalog;

/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];
script('snackcheck', 'app');
style('snackcheck', 'app');
$pageId = (string)($_['pageId'] ?? 'log');
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$locale = $l->getLanguageCode();
$pageMeta = [
	'log' => ['title' => $l->t('Log'), 'help' => $l->t('Tap a snack. Done.'), 'icon' => 'utensils'],
	'mymonth' => ['title' => $l->t('My month'), 'help' => $l->t('What payroll will deduct this period.'), 'icon' => 'calendar'],
	'pulse' => ['title' => $l->t('Kitchen overview'), 'help' => $l->t('What is running low and what to restock.'), 'icon' => 'activity'],
	'catalog' => ['title' => $l->t('Catalog'), 'help' => $l->t('Prices, stock, and what people can tap.'), 'icon' => 'package'],
	'users' => ['title' => $l->t('Users / totals'), 'help' => $l->t('Period totals and booking for others.'), 'icon' => 'users'],
	'periods' => ['title' => $l->t('Periods'), 'help' => $l->t('Open and close payroll periods.'), 'icon' => 'calendar-range'],
	'brreport' => ['title' => $l->t('Payroll summary report'), 'help' => $l->t('Export totals for payroll.'), 'icon' => 'file-text'],
	'sites' => ['title' => $l->t('Sites'), 'help' => $l->t('Kitchens and who manages them.'), 'icon' => 'building-2'],
	'audit' => ['title' => $l->t('Audit'), 'help' => $l->t('Who changed what, and when.'), 'icon' => 'clipboard-list'],
	'settings' => ['title' => $l->t('Settings'), 'help' => $l->t('Access, subsidy, emails, and license.'), 'icon' => 'settings'],
	'hospitality' => ['title' => $l->t('Hospitality'), 'help' => $l->t('Company treats and who may use them.'), 'icon' => 'coffee'],
];
$meta = $pageMeta[$pageId] ?? ['title' => $l->t('SnackCheck'), 'help' => '', 'icon' => 'fridge'];
$pageTitle = (string)($_['pageTitle'] ?? $meta['title']);
if ($pageId === 'mymonth' && !empty($_['periodLabel']) && empty($_['pageTitle'])) {
	$pageTitle = $l->t('My month') . ' · ' . (string)$_['periodLabel'];
}
$pageHelp = (string)($_['pageHelp'] ?? $meta['help']);
$headerIcon = (string)($_['pageIcon'] ?? $meta['icon']);
$logUrl = $urlGenerator->linkToRoute('snackcheck.page.log');

include __DIR__ . '/common/navigation.php';
?>
<div id="app-content"
	 class="snk-app snk-app--<?php p($pageId); ?>"
	 data-snk-page="<?php p($pageId); ?>"
	 lang="<?php p($htmlLang); ?>"
	 data-snk-locale="<?php p($locale); ?>"
	 data-snk-html-lang="<?php p($htmlLang); ?>">
	<a class="snk-skip-link" href="#snk-main-content"><?php p($l->t('Skip to content')); ?></a>
	<a class="snk-skip-link snk-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
	<div id="snk-live-region" class="snk-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="snk-alert-region" class="snk-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="snk-shell">
		<header class="snk-page-header" aria-labelledby="snk-page-title">
			<nav class="snk-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li>
						<a class="snk-breadcrumb__brand" href="<?php p($logUrl); ?>"><?php p($l->t('SnackCheck')); ?></a>
					</li>
					<li class="snk-breadcrumb__sep" aria-hidden="true">/</li>
					<?php if ($pageId === 'settings'): ?>
						<li>
							<a href="<?php p($urlGenerator->linkToRoute('snackcheck.page.settingsIndex')); ?>"><?php p($l->t('Settings')); ?></a>
						</li>
						<li class="snk-breadcrumb__sep" aria-hidden="true">/</li>
						<li class="snk-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
					<?php else: ?>
						<li class="snk-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
					<?php endif; ?>
				</ol>
			</nav>
			<div class="snk-page-header__main">
				<div class="snk-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIcon, 'snk-page-header__icon-svg')); ?>
				</div>
				<div class="snk-page-header__text">
					<h1 id="snk-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== '' || $pageId === 'log'): ?>
						<p class="snk-page-header__lead"<?php if ($pageId === 'log'): ?> id="snk-log-lead"<?php endif; ?>><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="snk-page-actions" class="snk-page-header__actions" aria-live="polite"></div>
			</div>
			<?php if (!empty($_['multiSite']) && !empty($_['sites']) && $pageId !== 'mymonth'): ?>
				<div class="snk-scope-strip snk-site-scope" role="navigation" aria-label="<?php p($l->t('Site scope')); ?>">
					<?php
					$siteCount = is_countable($_['sites']) ? count($_['sites']) : 0;
					$forceSelect = !empty($_['isAppAdmin']) && $siteCount > 1;
					$single = $siteCount === 1;
					?>
					<?php if ($single && empty($_['isAppAdmin'])): ?>
						<span class="snk-scope-strip__label" id="snk-site-scope-label"><?php p($l->t('Site')); ?></span>
						<span id="snk-site-select" class="snk-scope-strip__value snk-site-label" aria-labelledby="snk-site-scope-label" aria-live="polite">
							<?php
							$only = $_['sites'][0];
							p(is_object($only) ? $only->getName() : ($only['name'] ?? ''));
							?>
						</span>
					<?php else: ?>
						<label class="snk-scope-strip__label" for="snk-site-select"><?php p($l->t('Site')); ?></label>
						<select id="snk-site-select" class="snk-select"<?php if (!empty($_['sitePickRequired']) || ((int)($_['currentSiteId'] ?? 0) <= 0 && $forceSelect)) { ?> required aria-invalid="true"<?php } ?>>
							<?php
							$curSite = (int)($_['currentSiteId'] ?? 0);
							$needPick = !empty($_['sitePickRequired']) || ($forceSelect && $curSite <= 0);
							if ($needPick):
							?>
								<option value="" selected disabled><?php p($l->t('Choose a site…')); ?></option>
							<?php endif; ?>
							<?php foreach ($_['sites'] as $site): ?>
								<option value="<?php p($site->getId()); ?>" <?php if (!$needPick && (int)$site->getId() === $curSite) p('selected'); ?>><?php p($site->getName()); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</header>
		<main id="snk-main-content" class="snk-main" tabindex="-1" aria-labelledby="snk-page-title">
			<div class="snk-page-stack">
			<?php
			$partial = __DIR__ . '/pages/' . preg_replace('/[^a-z]/', '', $pageId) . '.php';
			if (is_file($partial)) {
				include $partial;
			} else {
				$icon = 'search';
				$title = $l->t('Page not found');
				$text = $l->t('Use the sidebar to open Log, My month, or Settings.');
				include __DIR__ . '/parts/snk-empty-state.php';
			}
			?>
			</div>
		</main>
	</div>
	<div id="snk-toast" class="snk-toast" role="status" aria-live="polite" data-undo-label="<?php p($l->t('Undo')); ?>" hidden></div>
</div>
