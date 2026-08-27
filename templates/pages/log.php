<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

use OCA\SnackCheck\Service\IconCatalog;

$tagLabels = [
	'vegan' => $l->t('Vegan'),
	'vegetarian' => $l->t('Vegetarian'),
	'gluten_free' => $l->t('Gluten-free'),
	'lactose_free' => $l->t('Lactose-free'),
	'contains_nuts' => $l->t('Contains nuts'),
	'contains_alcohol' => $l->t('Alcohol'),
];
$categoryLabels = [
	'drink' => $l->t('Drinks'),
	'snack' => $l->t('Snacks'),
	'alcohol' => $l->t('Alcohol'),
	'other' => $l->t('Other'),
];
$itemGroups = $_['itemGroups'] ?? [];
$items = $_['items'] ?? [];
$presentCats = [];
foreach ($itemGroups as $g) {
	$presentCats[] = $g['category'];
}
?>
<section class="snk-section" aria-label="<?php p($l->t('Log')); ?>">
	<?php if (!empty($_['sitePickRequired'])): ?>
		<div class="snk-callout snk-callout--warn" role="status">
			<p><?php p($l->t('Pick a site above before logging. Each kitchen has its own catalog.')); ?></p>
		</div>
	<?php endif; ?>
	<?php if (!empty($_['periodClosed'])): ?>
		<div class="snk-callout snk-callout--warn" role="status">
			<p><?php p($l->t('Period closed. Ask a kitchen admin to open the next period before logging.')); ?></p>
			<p class="snk-actions">
				<?php if (!empty($_['isAppAdmin'])): ?>
					<button type="button" class="snk-btn snk-btn--primary" data-snk-action="open-next-period"><?php p($l->t('Open next period')); ?></button>
				<?php else: ?>
					<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.mymonth')); ?>"><?php p($l->t('See My month')); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>
	<?php if (!empty($_['shelfFocus'])): ?>
		<div class="snk-callout snk-callout--ok" role="status">
			<p class="snk-lead"><?php p($l->t('Shelf item')); ?>: <strong><?php p($_['shelfItemName'] ?? ''); ?></strong></p>
			<p class="snk-muted"><?php p($l->t('Tap below to log this snack. Done.')); ?></p>
		</div>
	<?php endif; ?>

	<?php if (empty($items)): ?>
		<?php
		if (!empty($_['sitePickRequired'])) {
			$icon = 'map-pin';
			$title = $l->t('Choose a site');
			$text = $l->t('Pick your kitchen above, then tap a snack here.');
			$actionsHtml = '<button type="button" class="snk-btn snk-btn--primary" data-snk-action="focus-site">'
				. htmlspecialchars($l->t('Choose site'), ENT_QUOTES, 'UTF-8')
				. '</button>';
		} else {
			$icon = 'package';
			$title = $l->t('No catalog items yet.');
			$text = $l->t('Kitchen managers can load a starter catalog or add items.');
			$actionsHtml = '';
			if (!empty($_['isAppAdmin']) || !empty($_['isManager'])) {
				$actionsHtml = '<button type="button" class="snk-btn snk-btn--primary" data-snk-action="starter">'
					. htmlspecialchars($l->t('Load starter catalog'), ENT_QUOTES, 'UTF-8')
					. '</button>';
			} else {
				$actionsHtml = '<a class="snk-btn snk-btn--primary" href="'
					. htmlspecialchars($urlGenerator->linkToRoute('snackcheck.page.mymonth'), ENT_QUOTES, 'UTF-8')
					. '">' . htmlspecialchars($l->t('See My month'), ENT_QUOTES, 'UTF-8') . '</a>';
			}
		}
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<article class="snk-card">
			<header class="snk-card__header">
				<div class="snk-card__header-text">
					<h2 class="snk-card__title snk-sr-only"><?php p($l->t('Tap a snack')); ?></h2>
				</div>
			</header>
			<div class="snk-card__body">
		<?php if (empty($_['periodClosed'])): ?>
			<?php
			$showModes = !empty($_['canProxy']) || !empty($_['hospitalityAllowed']);
			?>
			<?php if ($showModes): ?>
				<div class="snk-mode-bar snk-mode-bar--surface" role="radiogroup" aria-label="<?php p($l->t('Who is this for?')); ?>">
					<label class="snk-mode-chip">
						<input type="radio" name="snk-log-mode" value="self" checked data-snk-mode />
						<span><?php p($l->t('Me')); ?></span>
					</label>
					<?php if (!empty($_['canProxy'])): ?>
						<label class="snk-mode-chip">
							<input type="radio" name="snk-log-mode" value="proxy" data-snk-mode />
							<span><?php p($l->t('Colleague')); ?></span>
						</label>
					<?php endif; ?>
					<?php if (!empty($_['hospitalityAllowed'])): ?>
						<label class="snk-mode-chip">
							<input type="radio" name="snk-log-mode" value="hospitality" data-snk-mode />
							<span><?php p($l->t('Company')); ?></span>
						</label>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if (!empty($_['canProxy'])): ?>
				<?php
				$siteId = (int)($_['siteId'] ?? 0);
				$fieldId = 'snk-proxy-target';
				$resultsId = 'snk-proxy-user-results';
				$reasonHintId = 'snk-proxy-reason-hint';
				$proxyPanelHidden = true;
				$showLead = true;
				include __DIR__ . '/../parts/snk-proxy-panel.php';
				?>
			<?php endif; ?>
			<?php if (!empty($_['hospitalityAllowed'])): ?>
				<section class="snk-card snk-filter-panel snk-mode-panel" id="snk-mode-hospitality" aria-labelledby="snk-mode-hosp-title" hidden>
					<header class="snk-filter-panel__head">
						<h2 id="snk-mode-hosp-title"><?php p($l->t('Company')); ?></h2>
						<p class="snk-filter-panel__intro snk-mode-panel__lead" role="status"><?php p($l->t('Enter a reason, then tap a snack below.')); ?></p>
					</header>
					<div class="snk-filter-panel__body">
						<label class="snk-field">
							<span><?php p($l->t('Why? (short note)')); ?></span>
							<input class="snk-input" name="hospitalityReason" id="snk-hosp-reason" required minlength="3" maxlength="500" autocomplete="off" aria-describedby="snk-hosp-reason-hint" />
							<span id="snk-hosp-reason-hint" class="snk-muted"><?php p($l->t('At least 3 characters — e.g. Guest visit')); ?></span>
						</label>
					</div>
				</section>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (count($items) > 6 || count($presentCats) > 1): ?>
			<div class="snk-log-browse" data-snk-log-browse>
				<label class="snk-field snk-log-find">
					<span class="snk-sr-only"><?php p($l->t('Find a snack')); ?></span>
					<input type="search"
						class="snk-input"
						data-snk-log-find
						autocomplete="off"
						placeholder="<?php p($l->t('Find a snack…')); ?>"
						aria-controls="snk-log-catalog" />
				</label>
				<?php if (count($presentCats) > 1): ?>
					<section class="snk-quick-filters" aria-labelledby="snk-log-cat-label">
						<p class="snk-quick-filters__label" id="snk-log-cat-label"><?php p($l->t('Category')); ?></p>
						<nav class="snk-filter-bar" data-snk-log-filters role="group" aria-labelledby="snk-log-cat-label">
							<button type="button" class="snk-filter snk-filter--active" data-snk-log-cat="all" aria-pressed="true"><?php p($l->t('All')); ?></button>
							<?php foreach ($presentCats as $c): ?>
								<button type="button" class="snk-filter" data-snk-log-cat="<?php p($c); ?>" aria-pressed="false"><?php p($categoryLabels[$c] ?? $c); ?></button>
							<?php endforeach; ?>
						</nav>
					</section>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div id="snk-log-catalog" data-snk-log-catalog>
			<p class="snk-muted snk-log-empty-filter" data-snk-log-empty hidden role="status"><?php p($l->t('No snacks match that search.')); ?></p>
			<?php
			$siteId = (int)($_['siteId'] ?? 0);
			$periodClosed = !empty($_['periodClosed']);
			$shelfFocus = !empty($_['shelfFocus']);
			foreach ($itemGroups as $group):
				$cat = $group['category'];
				$label = $categoryLabels[$cat] ?? $cat;
				$sectionIcon = IconCatalog::forCategory($cat);
			?>
				<section class="snk-log-group" data-snk-log-group data-snk-cat="<?php p($cat); ?>" aria-labelledby="snk-log-group-<?php p($cat); ?>">
					<h3 class="snk-log-group__title" id="snk-log-group-<?php p($cat); ?>">
						<span class="snk-log-group__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($sectionIcon)); ?></span>
						<?php p($label); ?>
					</h3>
					<ul class="snk-tile-grid" role="list">
						<?php foreach ($group['items'] as $item): ?>
							<?php include __DIR__ . '/../parts/snk-log-tile.php'; ?>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>

		<?php if (empty($_['periodClosed'])): ?>
			<details class="snk-details snk-log-advanced" id="snk-log-advanced">
				<summary><?php p($l->t('More options')); ?>
					<span class="snk-muted"> — <?php p($l->t('quantity')); ?></span>
				</summary>
				<div class="snk-log-controls" id="snk-log-controls">
					<div class="snk-qty-bar" role="group" aria-label="<?php p($l->t('Quantity')); ?>">
						<span class="snk-qty-bar__label" id="snk-qty-label"><?php p($l->t('How many?')); ?></span>
						<?php foreach ([1, 2, 3, 5] as $q): ?>
							<button type="button"
								class="snk-qty-chip<?php if ($q === 1) { p(' is-active'); } ?>"
								data-snk-qty="<?php p($q); ?>"
								aria-pressed="<?php p($q === 1 ? 'true' : 'false'); ?>"
								aria-describedby="snk-qty-label">
								<?php p((string)$q); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</details>
		<?php endif; ?>
			</div>
		</article>
	<?php endif; ?>
</section>
