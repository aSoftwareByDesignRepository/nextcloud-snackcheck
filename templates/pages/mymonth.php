<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
use OCA\SnackCheck\Service\MyMonthStatementPresenter;

$presenter = new MyMonthStatementPresenter();
$deductCents = (int)($_['deductCents'] ?? 0);
$fmtEuro = static fn (int $cents): string => $presenter->formatEuroWeb($cents);
$breakdownRows = array_values($_['breakdownRows'] ?? []);
$hasLines = !empty($_['lines']);
?>
<section class="snk-section snk-section--mymonth" aria-label="<?php p($l->t('My month')); ?>">
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

	<article class="snk-statement" aria-labelledby="snk-statement-title">
		<header class="snk-statement__hero">
			<h2 id="snk-statement-title" class="snk-statement__label"><?php p($l->t('To deduct')); ?></h2>
			<p class="snk-statement__amount" data-snk-hero-value><?php p($fmtEuro($deductCents)); ?></p>
			<p class="snk-statement__lead snk-muted"><?php p($l->t('This is what payroll takes from your pay for this period.')); ?></p>
		</header>

		<?php if ($hasLines): ?>
			<div class="snk-statement__breakdown" role="group" aria-label="<?php p($l->t('How this adds up')); ?>">
				<h3 class="snk-statement__breakdown-title"><?php p($l->t('How this adds up')); ?></h3>
				<dl class="snk-money-list">
					<?php foreach ($breakdownRows as $row): ?>
						<div class="snk-money-list__row">
							<dt><?php p($row['label']); ?></dt>
							<dd><?php p($row['value']); ?></dd>
						</div>
					<?php endforeach; ?>
					<div class="snk-money-list__row snk-money-list__row--total">
						<dt><?php p($l->t('To deduct')); ?></dt>
						<dd><?php p($fmtEuro($deductCents)); ?></dd>
					</div>
				</dl>
			</div>

			<footer class="snk-statement__actions">
				<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.downloadMyMonthPdf')); ?>"><?php p($l->t('Download PDF for payroll')); ?></a>
				<p class="snk-statement__actions-hint snk-muted"><?php p($l->t('Share this PDF with HR if they ask for proof.')); ?></p>
			</footer>
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
		<section class="snk-subsection" aria-labelledby="snk-mymonth-items-title">
			<h2 id="snk-mymonth-items-title" class="snk-subsection__title"><?php p($l->t('Your consumption this period')); ?></h2>
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
							<th scope="col" class="snk-table__num"><?php p($l->t('Qty')); ?></th>
							<th scope="col" class="snk-table__num"><?php p($l->t('Total')); ?></th>
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
								<td class="snk-table__num"><?php p($line['qty']); ?></td>
								<td class="snk-table__num"><?php if (!empty($line['free'])): ?><?php p($l->t('Free')); ?><?php else: ?><?php p($fmtEuro((int)$line['line_total_cents'])); ?><?php endif; ?></td>
								<td class="snk-muted snk-tabular"><?php p($line['createdAt'] ?? ''); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</article>
		</section>
	<?php endif; ?>
</section>
