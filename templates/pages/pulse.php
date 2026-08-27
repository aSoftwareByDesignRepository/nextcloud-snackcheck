<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ $pulse = $_['pulse'] ?? ['ranks'=>[],'topUp'=>[],'shoppingList'=>[]]; $cat = $_['category'] ?? 'all'; $cats = $_['categories'] ?? ['all'];
$exportList = !empty($pulse['topUp']) ? $pulse['topUp'] : ($pulse['shoppingList'] ?? []);
?>
<section class="snk-section" aria-label="<?php p($l->t('Kitchen overview')); ?>">
	<section class="snk-quick-filters" aria-labelledby="snk-pulse-cat-label">
		<p class="snk-quick-filters__label" id="snk-pulse-cat-label"><?php p($l->t('Category')); ?></p>
		<nav class="snk-filter-bar" aria-labelledby="snk-pulse-cat-label">
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
	</section>

	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Restock list')); ?></h2>
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
		$title = $l->t('Nothing needs restocking');
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
							· <?php p($l->t('In fridge')); ?> <?php p($t['onHand'] ?? '—'); ?> / <?php p($l->t('Target stock')); ?> <?php p($t['parLevel'] ?? '—'); ?>
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
		<details class="snk-details snk-details--flush"<?php if (empty($pulse['ranks'])) { ?> open<?php } ?>>
			<summary><?php p($l->t("What's popular")); ?></summary>
			<?php if (empty($pulse['ranks'])): ?>
				<?php
				$icon = 'activity';
				$title = $l->t('No snacks logged in these days yet');
				$text = $l->t('After people log snacks, rankings appear here.');
				$actionsHtml = '';
				include __DIR__ . '/../parts/snk-empty-state.php';
				?>
			<?php else:
				$paceDays = (int)($_['paceWindowDays'] ?? 14);
				if ($paceDays < 1) {
					$paceDays = 14;
				}
				?>
				<div class="snk-rank-panel">
					<p class="snk-rank-panel__lead snk-muted"><?php p($l->t('Most logged in the last %s days.', [(string)$paceDays])); ?></p>
					<ol class="snk-rank-list">
						<?php
						$place = 0;
						foreach ($pulse['ranks'] as $r):
							$place += 1;
							$name = (string)($r['name'] ?? '');
							$qty = (int)($r['qty'] ?? 0);
							$eurCents = (int)($r['eurCents'] ?? 0);
							$share = (float)($r['qtySharePct'] ?? 0.0);
							$shareWidth = max(0, min(100, (int)round($share)));
							$eurLabel = number_format($eurCents / 100, 2, ',', '.') . ' €';
							$shareDecimals = abs($share - round($share)) < 0.05 ? 0 : 1;
							$shareLabel = number_format($share, $shareDecimals, ',', '.');
							?>
							<li class="snk-rank<?php if ($place <= 3) { ?> snk-rank--top<?php } ?>">
								<span class="snk-rank__place" aria-hidden="true"><?php p((string)$place); ?></span>
								<div class="snk-rank__main">
									<span class="snk-rank__name"><?php p($name); ?></span>
									<div class="snk-rank__stats">
										<span class="snk-rank__stat">
											<strong><?php p((string)$qty); ?></strong>
											<span class="snk-rank__stat-unit">×</span>
										</span>
										<span class="snk-rank__stat snk-rank__stat--money"><?php p($eurLabel); ?></span>
										<?php if ($share > 0): ?>
											<span class="snk-rank__stat snk-muted"><?php p($l->t('%s%% of logs', [$shareLabel])); ?></span>
										<?php endif; ?>
									</div>
									<div class="snk-rank__bar" role="presentation" aria-hidden="true">
										<span class="snk-rank__bar-fill" style="width: <?php p((string)$shareWidth); ?>%;"></span>
									</div>
								</div>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>
		</details>
	</article>
</section>
