<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ $pulse = $_['pulse'] ?? ['ranks'=>[],'topUp'=>[],'shoppingList'=>[]]; $cat = $_['category'] ?? 'all'; $cats = $_['categories'] ?? ['all'];
$exportList = !empty($pulse['topUp']) ? $pulse['topUp'] : ($pulse['shoppingList'] ?? []);
?>
<section class="snk-section" aria-label="<?php p($l->t('Kitchen pulse')); ?>">
	<nav class="snk-filter-bar" aria-label="<?php p($l->t('Category filter')); ?>">
		<?php foreach ($cats as $c): ?>
			<?php
			$label = match ($c) {
				'all' => $l->t('All'),
				'drink' => $l->t('Drinks'),
				'snack' => $l->t('Snacks'),
				'alcohol' => $l->t('Alcohol'),
				'other' => $l->t('Other'),
				default => $c,
			};
			$href = $urlGenerator->linkToRoute('snackcheck.page.pulse', array_filter([
				'siteId' => $_['siteId'] ?? null,
				'category' => $c === 'all' ? null : $c,
			]));
			?>
			<a class="snk-filter<?php if ($cat === $c) { p(' snk-filter--active'); } ?>"
				href="<?php p($href); ?>"
				<?php if ($cat === $c) { ?>aria-current="true"<?php } ?>><?php p($label); ?></a>
		<?php endforeach; ?>
	</nav>

	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Top-up')); ?></h2>
				<p class="snk-card__lead"><?php p($l->t('One tap restocks the suggested amount.')); ?></p>
			</div>
			<?php if ($exportList !== []): ?>
				<div class="snk-card__header-actions">
					<button type="button" class="snk-btn snk-btn--secondary" data-snk-action="shopping-csv"><?php p($l->t('Download CSV')); ?></button>
					<button type="button" class="snk-btn snk-btn--secondary" data-snk-action="shopping-print"><?php p($l->t('Print list')); ?></button>
				</div>
			<?php endif; ?>
		</header>
		<div class="snk-card__body">
	<?php if (empty($pulse['topUp'])): ?>
		<?php
		$icon = 'fridge';
		$title = $l->t('Nothing needs topping up');
		$text = $l->t('When stock runs low, suggested buys appear here.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<ul class="snk-list snk-list--actions">
			<?php foreach ($pulse['topUp'] as $t): ?>
				<li class="snk-list__row">
					<div class="snk-list__main">
						<strong><?php p($t['name']); ?></strong>
						<span class="snk-muted"><?php p($l->t('buy')); ?> <?php p($t['suggestedBuy'] ?? 0); ?>
							· <?php p($l->t('In fridge')); ?> <?php p($t['onHand'] ?? '—'); ?> / <?php p($l->t('Target')); ?> <?php p($t['parLevel'] ?? '—'); ?>
						</span>
					</div>
					<button type="button"
						class="snk-btn snk-btn--primary"
						data-snk-action="restock"
						data-item-id="<?php p($t['itemId'] ?? 0); ?>"
						data-default-qty="<?php p(max(1, (int)($t['suggestedBuy'] ?? 1))); ?>"
						data-instant="1"
						title="<?php p($l->t('Adds the suggested quantity in one tap.')); ?>"
						<?php if (empty($t['itemId'])) { ?>disabled<?php } ?>>
						<?php p($l->t('Restock')); ?>
						<span class="snk-btn__sub">+<?php p(max(1, (int)($t['suggestedBuy'] ?? 1))); ?></span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
		</div>
	</article>

	<article class="snk-card">
		<details class="snk-details snk-details--flush">
			<summary><?php p($l->t("What's selling")); ?></summary>
			<?php if (empty($pulse['ranks'])): ?>
				<?php
				$icon = 'activity';
				$title = $l->t('No consumption in the pace window yet');
				$text = $l->t('After people log snacks, rankings appear here.');
				$actionsHtml = '';
				include __DIR__ . '/../parts/snk-empty-state.php';
				?>
			<?php else: ?>
				<ol class="snk-rank-list">
					<?php foreach ($pulse['ranks'] as $r): ?>
						<li><?php p($r['name']); ?> — <?php p($r['qty']); ?> × · <?php p(number_format($r['eurCents']/100, 2, ',', '.')); ?> €</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</details>
	</article>
</section>
