<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$fmtEuro = static function (int $cents): string {
	return number_format($cents / 100, 2, ',', '.') . ' €';
};
$deductCents = (int)($_['deductCents'] ?? 0);
$grossCents = (int)($_['grossCents'] ?? 0);
$subsidyCents = (int)($_['subsidyCents'] ?? 0);
$freeQty = (int)($_['freeQty'] ?? 0);
$hasLines = !empty($_['lines']);
?>
<section class="snk-section" aria-label="<?php p($l->t('My month')); ?>">
	<?php if (!empty($_['periodClosed'])): ?>
		<div class="snk-callout snk-callout--warn" role="status">
			<p><?php p($l->t('Showing the last closed period. Logging is locked until the next period opens.')); ?></p>
			<?php if (!empty($_['isAppAdmin'])): ?>
				<p class="snk-actions">
					<button type="button" class="snk-btn snk-btn--primary" data-snk-action="open-next-period"><?php p($l->t('Open next period')); ?></button>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<article class="snk-hero" aria-labelledby="snk-hero-deduct-label">
		<div class="snk-hero__main">
			<p id="snk-hero-deduct-label" class="snk-hero__label"><?php p($l->t('To deduct')); ?></p>
			<p class="snk-hero__value" data-snk-hero-value><?php p($fmtEuro($deductCents)); ?></p>
			<p class="snk-hero__hint snk-muted"><?php p($l->t('This is what payroll takes from your pay for this period.')); ?></p>
		</div>
		<dl class="snk-hero__stats">
			<div class="snk-hero__stat">
				<dt><?php p($l->t('Gross')); ?></dt>
				<dd><?php p($fmtEuro($grossCents)); ?></dd>
			</div>
			<div class="snk-hero__stat">
				<dt><?php p($l->t('Subsidy')); ?></dt>
				<dd><?php p($fmtEuro($subsidyCents)); ?></dd>
			</div>
			<?php if ($freeQty > 0): ?>
				<div class="snk-hero__stat">
					<dt><?php p($l->t('Free items')); ?></dt>
					<dd><?php p((string)$freeQty); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
		<?php if ($hasLines): ?>
			<div class="snk-hero__actions">
				<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.downloadMyMonthPdf')); ?>"><?php p($l->t('Download PDF')); ?></a>
			</div>
		<?php endif; ?>
	</article>

	<?php if (!$hasLines): ?>
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
							<td><?php if (!empty($line['free'])): ?><?php p($l->t('Free')); ?><?php else: ?><?php p($fmtEuro((int)$line['line_total_cents'])); ?><?php endif; ?></td>
							<td class="snk-muted snk-tabular"><?php p($line['createdAt'] ?? ''); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</article>
	<?php endif; ?>
</section>
