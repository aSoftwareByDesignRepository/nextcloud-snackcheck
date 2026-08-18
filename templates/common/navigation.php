<?php
/**
 * SnackCheck sidebar — Nextcloud #app-navigation (Check-family chrome).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\SnackCheck\Service\IconCatalog;

/** @var \OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];
$pageId = (string)($_['pageId'] ?? 'log');
$nav = $_['nav'] ?? [];

$groups = [
	'me' => $l->t('Me'),
	'kitchen' => $l->t('Kitchen'),
	'money' => $l->t('Money'),
	'admin' => $l->t('Admin'),
];
$byGroup = [];
foreach ($nav as $item) {
	$g = (string)($item['group'] ?? 'me');
	$byGroup[$g][] = $item;
}

$roleLabel = $l->t('Member');
if (!empty($_['isAppAdmin'])) {
	$roleLabel = $l->t('App admin');
} elseif (!empty($_['isManager'])) {
	$roleLabel = $l->t('Kitchen manager');
}
?>
<nav id="app-navigation" class="snk-nav" role="navigation" aria-label="<?php p($l->t('SnackCheck navigation')); ?>">
	<div class="snk-brand">
		<span class="snk-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('fridge', 'snk-brand__icon-svg')); ?>
		</span>
		<div class="snk-brand__text">
			<h2 class="snk-brand__title"><?php p($l->t('SnackCheck')); ?></h2>
			<p class="snk-brand__subtitle"><?php p($l->t('Office snacks & drinks')); ?></p>
			<span class="snk-badge"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<div class="snk-nav__body">
		<?php foreach ($groups as $gid => $glabel):
			if (empty($byGroup[$gid])) {
				continue;
			}
			?>
			<section class="snk-nav__group" aria-labelledby="snk-nav-<?php p($gid); ?>">
				<h3 class="snk-nav__group-label" id="snk-nav-<?php p($gid); ?>"><?php p($glabel); ?></h3>
				<ul class="snk-nav__list">
					<?php foreach ($byGroup[$gid] as $item):
						$active = ($item['id'] ?? '') === $pageId;
						$icon = (string)($item['icon'] ?? 'layout-grid');
						$hint = (string)($item['hint'] ?? '');
						?>
						<li class="snk-nav__item<?php if ($active) { p(' is-active'); } ?>">
							<a class="snk-nav__link<?php if ($active) { p(' is-active'); } ?>"
							   href="<?php p($urlGenerator->linkToRoute($item['route'])); ?>"
							   <?php if ($active): ?>aria-current="page"<?php endif; ?>>
								<span class="snk-nav__icon" aria-hidden="true">
									<?php print_unescaped(IconCatalog::render($icon)); ?>
								</span>
								<span class="snk-nav__label">
									<span class="snk-nav__name"><?php p($l->t($item['label'])); ?></span>
									<?php if ($hint !== ''): ?>
										<span class="snk-nav__hint"><?php p($l->t($hint)); ?></span>
									<?php endif; ?>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	</div>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</nav>
