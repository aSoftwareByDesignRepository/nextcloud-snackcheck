<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<section class="snk-section" aria-label="<?php p($l->t('Users / totals')); ?>">
	<p class="snk-lead"><?php p($l->t('Period')); ?>: <strong><?php p($_['periodLabel'] ?? ''); ?></strong>
		<?php if (!empty($_['privacyTotalsOnly'])): ?>
			· <span class="snk-badge"><?php p($l->t('Totals only (privacy)')); ?></span>
		<?php endif; ?>
	</p>

	<?php if (!empty($_['canProxy'])): ?>
		<?php
		$tagLabels = [
			'vegan' => $l->t('Vegan'),
			'vegetarian' => $l->t('Vegetarian'),
			'gluten_free' => $l->t('Gluten-free'),
			'lactose_free' => $l->t('Lactose-free'),
			'contains_nuts' => $l->t('Contains nuts'),
			'contains_alcohol' => $l->t('Alcohol'),
		];
		?>
		<section class="snk-card snk-card--proxy" aria-labelledby="snk-users-proxy-title">
			<header class="snk-card__header">
				<div class="snk-card__header-text">
					<h2 id="snk-users-proxy-title" class="snk-card__title"><?php p($l->t('Log for a colleague')); ?></h2>
					<p class="snk-card__lead"><?php p($l->t('Available even when privacy hides itemized lines.')); ?></p>
				</div>
			</header>
			<div class="snk-card__body">
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
			<?php elseif (!empty($_['sitePickRequired']) || (int)($_['siteId'] ?? 0) <= 0): ?>
				<div class="snk-callout snk-callout--warn" role="status">
					<p><?php p($l->t('Pick a site above before logging. Each kitchen has its own catalog.')); ?></p>
					<p class="snk-actions">
						<button type="button" class="snk-btn snk-btn--primary" data-snk-action="focus-site"><?php p($l->t('Choose site')); ?></button>
					</p>
				</div>
			<?php elseif (empty($_['proxyItems'])): ?>
				<?php
				$icon = 'package';
				$title = $l->t('No catalog items yet.');
				$text = $l->t('Open Catalog to load starters or add the first snack.');
				$actionsHtml = '<a class="snk-btn snk-btn--primary" href="'
					. htmlspecialchars($urlGenerator->linkToRoute('snackcheck.page.catalog'), ENT_QUOTES, 'UTF-8')
					. '">' . htmlspecialchars($l->t('Open Catalog'), ENT_QUOTES, 'UTF-8') . '</a>';
				include __DIR__ . '/../parts/snk-empty-state.php';
				?>
			<?php else: ?>
				<div class="snk-log-controls" id="snk-users-proxy-controls">
					<?php /* Force proxy mode for Users AC-35 — no Me/Company here */ ?>
					<input type="radio" name="snk-log-mode" value="proxy" checked data-snk-mode hidden aria-hidden="true" />
					<?php
					$siteId = (int)($_['siteId'] ?? 0);
					$fieldId = 'snk-users-proxy-target';
					$resultsId = 'snk-users-proxy-results';
					$reasonHintId = 'snk-users-proxy-reason-hint';
					$proxyPanelHidden = false;
					$showLead = true;
					$embedded = true;
					include __DIR__ . '/../parts/snk-proxy-panel.php';
					$embedded = false;
					?>
				</div>
				<ul class="snk-tile-grid" role="list" aria-label="<?php p($l->t('Catalog')); ?>">
					<?php
					$siteId = (int)($_['siteId'] ?? 0);
					$periodClosed = false;
					$shelfFocus = false;
					foreach ($_['proxyItems'] as $item):
						include __DIR__ . '/../parts/snk-log-tile.php';
					endforeach;
					?>
				</ul>
			<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if (empty($_['users'])): ?>
		<?php
		$icon = 'users';
		$title = $l->t('No personal consumption this period.');
		$text = $l->t('Totals appear when people log snacks.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<?php foreach ($_['users'] as $u): ?>
			<article class="snk-card" aria-label="<?php p($u['displayName']); ?>">
				<header class="snk-card__header">
					<div class="snk-card__header-text">
						<h2 class="snk-card__title"><?php p($u['displayName']); ?></h2>
						<p class="snk-card__lead">
							<?php p($l->t('To deduct')); ?>:
							<strong><?php p(number_format($u['deductCents']/100, 2, ',', '.')); ?> €</strong>
							· <?php p($l->t('Gross')); ?> <?php p(number_format($u['grossCents']/100, 2, ',', '.')); ?> €
							· <?php p($l->t('Subsidy')); ?> <?php p(number_format($u['subsidyCents']/100, 2, ',', '.')); ?> €
						</p>
					</div>
				</header>
				<div class="snk-card__body">
				<?php if (!empty($u['lines'])): ?>
					<div class="snk-table-wrap">
						<table class="snk-table">
						<thead>
							<tr>
								<th scope="col"><?php p($l->t('Item')); ?></th>
								<th scope="col"><?php p($l->t('Qty')); ?></th>
								<th scope="col"><?php p($l->t('Total')); ?></th>
								<th scope="col"><?php p($l->t('Actions')); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($u['lines'] as $line): ?>
							<tr>
								<td><?php p($line['name']); ?><?php if (!empty($line['free'])): ?> <span class="snk-badge"><?php p($l->t('Free')); ?></span><?php endif; ?></td>
								<td><?php p($line['qty']); ?></td>
								<td><?php p(number_format($line['line_total_cents']/100, 2, ',', '.')); ?> €</td>
								<td>
									<button type="button" class="snk-btn snk-btn--danger" data-snk-action="void-log" data-log-id="<?php p($line['id']); ?>"><?php p($l->t('Void')); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php elseif (!empty($_['privacyTotalsOnly'])): ?>
					<p class="snk-muted"><?php p($l->t('Itemized lines hidden by privacy mode.')); ?></p>
				<?php endif; ?>
				</div>
			</article>
		<?php endforeach; ?>
	<?php endif; ?>
	<dialog id="snk-void-dialog" class="snk-dialog" aria-labelledby="snk-void-title">
		<form method="dialog" data-snk-form="void-log">
			<h2 id="snk-void-title" class="snk-h2"><?php p($l->t('Void log')); ?></h2>
			<input type="hidden" name="logId" id="snk-void-log-id" />
			<label class="snk-field">
				<span><?php p($l->t('Reason (min. 3 characters)')); ?></span>
				<input name="reason" class="snk-input" required minlength="3" />
			</label>
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" class="snk-btn snk-btn--danger" value="confirm"><?php p($l->t('Void')); ?></button>
			</div>
		</form>
	</dialog>
</section>
