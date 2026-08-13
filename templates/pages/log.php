<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

$tagLabels = [
	'vegan' => $l->t('Vegan'),
	'vegetarian' => $l->t('Vegetarian'),
	'gluten_free' => $l->t('Gluten-free'),
	'lactose_free' => $l->t('Lactose-free'),
	'contains_nuts' => $l->t('Contains nuts'),
	'contains_alcohol' => $l->t('Alcohol'),
];
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
					<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.periods')); ?>"><?php p($l->t('Open Periods')); ?></a>
					<button type="button" class="snk-btn" data-snk-action="open-next-period"><?php p($l->t('Open next period')); ?></button>
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

	<?php if (empty($_['items'])): ?>
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
		<ul class="snk-tile-grid" role="list">
			<?php foreach ($_['items'] as $item): ?>
				<?php
				$priceLabel = !empty($item['free'])
					? $l->t('Free')
					: number_format($item['priceCents'] / 100, 2, ',', '.') . ' €';
				$aria = $item['name'] . ' — ' . $priceLabel;
				$tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
				$tileTags = [];
				foreach ($tags as $tag) {
					if ($tag === 'contains_alcohol') {
						$tileTags[] = $tag;
					}
				}
				if ($tileTags === [] && $tags !== []) {
					$tileTags[] = $tags[0];
				}
				?>
				<li>
					<button type="button"
						class="snk-tile<?php if (!empty($_['shelfFocus'])) { p(' snk-tile--focus'); } ?>"
						data-snk-action="log"
						data-item-id="<?php p($item['id']); ?>"
						data-site-id="<?php p($_['siteId']); ?>"
						aria-label="<?php p($aria); ?>"
						<?php if (!empty($_['periodClosed'])) { ?>disabled aria-disabled="true"<?php } ?>
						<?php if (!empty($_['shelfFocus'])) { ?>autofocus<?php } ?>>
						<span class="snk-tile__name"><?php p($item['name']); ?></span>
						<?php if (!empty($item['category'])): ?>
							<span class="snk-tile__meta"><?php p($item['category']); ?></span>
						<?php endif; ?>
						<?php if ($tileTags !== []): ?>
							<span class="snk-tile__tags">
								<?php foreach ($tileTags as $tag): ?>
									<span class="snk-tag"><?php p($tagLabels[$tag] ?? $tag); ?></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
						<span class="snk-tile__price">
							<?php if (!empty($item['free'])): ?>
								<?php p($l->t('Free')); ?>
							<?php else: ?>
								<?php p(number_format($item['priceCents'] / 100, 2, ',', '.') . ' €'); ?>
							<?php endif; ?>
						</span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if (empty($_['periodClosed'])): ?>
			<?php /* Bachus: tiles first — qty/colleague/company stay behind one disclosure below */ ?>
			<details class="snk-details snk-log-advanced" id="snk-log-advanced">
				<summary><?php p($l->t('More options')); ?>
					<span class="snk-muted"> — <?php p($l->t('quantity, colleague, company')); ?></span>
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
					<div class="snk-mode-bar" role="radiogroup" aria-label="<?php p($l->t('Who is this for?')); ?>">
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
					<?php if (!empty($_['canProxy'])): ?>
						<div class="snk-mode-panel" id="snk-mode-proxy" hidden>
							<p class="snk-muted" role="status"><?php p($l->t('Pick a colleague and reason, then tap a snack above.')); ?></p>
							<div class="snk-form snk-form--inline" data-snk-proxy-fields>
								<input type="hidden" name="siteId" value="<?php p($_['siteId']); ?>" />
								<label class="snk-field">
									<span><?php p($l->t('Colleague')); ?></span>
									<?php
									$name = 'targetUserId';
									$value = '';
									$picker = 'users';
									$single = true;
									$required = true;
									$listLabel = $l->t('Colleague');
									$chips = [];
									$fieldId = 'snk-proxy-target';
									include __DIR__ . '/../parts/snk-chip-field.php';
									?>
								</label>
							<label class="snk-field">
								<span><?php p($l->t('Reason (min. 3 characters)')); ?></span>
								<input name="proxyReason" id="snk-proxy-reason" required minlength="3" maxlength="500" />
							</label>
							</div>
							<div class="snk-chip-search">
							<label class="snk-field">
								<span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
								<input type="search" data-snk-user-search data-snk-search-scope="access" autocomplete="off" aria-controls="snk-proxy-user-results" />
							</label>
							<ul id="snk-proxy-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
							</div>
						</div>
					<?php endif; ?>
					<?php if (!empty($_['hospitalityAllowed'])): ?>
						<div class="snk-mode-panel" id="snk-mode-hospitality" hidden>
							<p class="snk-muted" role="status"><?php p($l->t('Enter a reason, then tap a snack above.')); ?></p>
							<label class="snk-field">
								<span><?php p($l->t('Reason (min. 3 characters)')); ?></span>
								<input name="hospitalityReason" id="snk-hosp-reason" required minlength="3" maxlength="500" />
							</label>
						</div>
					<?php endif; ?>
				</div>
			</details>
		<?php endif; ?>
			</div>
		</article>
	<?php endif; ?>
</section>
