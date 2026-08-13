<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<section class="snk-section" aria-label="<?php p($l->t('My month')); ?>">
	<?php if (!empty($_['periodClosed'])): ?>
		<div class="snk-callout snk-callout--warn" role="status">
			<p><?php p($l->t('Showing the last closed period. Logging is locked until the next period opens.')); ?></p>
			<?php if (!empty($_['isAppAdmin'])): ?>
				<p class="snk-actions">
					<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.periods')); ?>"><?php p($l->t('Open Periods')); ?></a>
					<button type="button" class="snk-btn" data-snk-action="open-next-period"><?php p($l->t('Open next period')); ?></button>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="snk-hero" aria-label="<?php p($l->t('To deduct')); ?>">
		<p class="snk-hero__label"><?php p($l->t('To deduct')); ?></p>
		<p class="snk-hero__value"><?php p(number_format(($_['deductCents'] ?? 0) / 100, 2, ',', '.') . ' €'); ?></p>
		<p class="snk-hero__meta">
			<?php p($l->t('Gross')); ?>: <?php p(number_format(($_['grossCents'] ?? 0) / 100, 2, ',', '.') . ' €'); ?>
			· <?php p($l->t('Subsidy')); ?>: <?php p(number_format(($_['subsidyCents'] ?? 0) / 100, 2, ',', '.') . ' €'); ?>
			<?php if (!empty($_['freeQty'])): ?>
				· <?php p($l->t('Free items logged')); ?>: <?php p((int)$_['freeQty']); ?>
			<?php endif; ?>
		</p>
		<?php if (!empty($_['lines'])): ?>
			<p class="snk-actions">
				<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.downloadMyMonthPdf')); ?>"><?php p($l->t('Download PDF')); ?></a>
			</p>
		<?php endif; ?>
	</div>
	<?php if (empty($_['lines'])): ?>
		<?php
		$icon = 'utensils';
		$title = $l->t('Nothing logged this month yet.');
		$text = $l->t('Log something in the kitchen — it shows up here for payroll.');
		$actionsHtml = '<a class="snk-btn snk-btn--primary" href="'
			. htmlspecialchars($urlGenerator->linkToRoute('snackcheck.page.log'), ENT_QUOTES, 'UTF-8')
			. '">' . htmlspecialchars($l->t('Log something in the kitchen'), ENT_QUOTES, 'UTF-8') . '</a>';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<article class="snk-card snk-card--table-solo">
			<div class="snk-table-wrap">
				<table class="snk-table">
				<caption class="snk-sr-only"><?php p($l->t('Your consumption this period')); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Item')); ?></th>
						<?php if (!empty($_['multiSite'])): ?>
						<th scope="col"><?php p($l->t('Site')); ?></th>
						<?php endif; ?>
						<th scope="col"><?php p($l->t('Qty')); ?></th>
						<th scope="col"><?php p($l->t('Total')); ?></th>
						<th scope="col"><?php p($l->t('When')); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($_['lines'] as $line): ?>
						<tr>
							<td><?php p($line['name']); ?><?php if (!empty($line['free'])): ?> <span class="snk-badge"><?php p($l->t('Free')); ?></span><?php endif; ?></td>
							<?php if (!empty($_['multiSite'])): ?>
							<td class="snk-muted"><?php p($line['siteName'] ?? ''); ?></td>
							<?php endif; ?>
							<td><?php p($line['qty']); ?></td>
							<td><?php if (!empty($line['free'])): ?><?php p($l->t('Free')); ?><?php else: ?><?php p(number_format($line['line_total_cents'] / 100, 2, ',', '.') . ' €'); ?><?php endif; ?></td>
							<td><?php p($line['createdAt'] ?? ''); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</article>
	<?php endif; ?>
</section>
