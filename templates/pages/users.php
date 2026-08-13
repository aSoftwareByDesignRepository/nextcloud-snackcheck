<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<section class="snk-section" aria-label="<?php p($l->t('Users / totals')); ?>">
	<p class="snk-lead"><?php p($l->t('Period')); ?>: <strong><?php p($_['periodLabel'] ?? ''); ?></strong>
		<?php if (!empty($_['privacyTotalsOnly'])): ?>
			· <span class="snk-badge"><?php p($l->t('Totals only (privacy)')); ?></span>
		<?php endif; ?>
	</p>

	<?php if (!empty($_['canProxy'])): ?>
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
							<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.periods')); ?>"><?php p($l->t('Open Periods')); ?></a>
							<button type="button" class="snk-btn" data-snk-action="open-next-period"><?php p($l->t('Open next period')); ?></button>
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
					<div class="snk-mode-panel" id="snk-mode-proxy">
						<p class="snk-muted" role="status"><?php p($l->t('Pick a colleague and reason, then tap a snack below.')); ?></p>
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
								$fieldId = 'snk-users-proxy-target';
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
							<input type="search" data-snk-user-search data-snk-search-scope="access" autocomplete="off" aria-controls="snk-users-proxy-results" />
						</label>
						<ul id="snk-users-proxy-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
						</div>
					</div>
				</div>
				<ul class="snk-tile-grid" role="list" aria-label="<?php p($l->t('Catalog')); ?>">
					<?php foreach ($_['proxyItems'] as $item): ?>
						<?php
						$priceLabel = !empty($item['free'])
							? $l->t('Free')
							: number_format($item['priceCents'] / 100, 2, ',', '.') . ' €';
						$aria = $item['name'] . ' — ' . $priceLabel;
						?>
						<li>
							<button type="button"
								class="snk-tile"
								data-snk-action="log"
								data-item-id="<?php p($item['id']); ?>"
								data-site-id="<?php p($_['siteId']); ?>"
								aria-label="<?php p($aria); ?>">
								<span class="snk-tile__name"><?php p($item['name']); ?></span>
								<?php if (!empty($item['category'])): ?>
									<span class="snk-tile__meta"><?php p($item['category']); ?></span>
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
				<button type="submit" class="snk-btn snk-btn--danger" value="confirm"><?php p($l->t('Void')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>
</section>
