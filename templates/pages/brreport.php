<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ $report = $_['report'] ?? ['byCategory'=>[],'byItem'=>[],'periodLabel'=>'']; ?>
<section class="snk-section" aria-label="<?php p($l->t('Payroll summary report')); ?>">
	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Aggregate report')); ?></h2>
				<p class="snk-card__lead">
					<?php p($l->t('Anonymized volume by category and item — no user identifiers.')); ?>
					· <?php p($l->t('Period')); ?>: <strong><?php p($report['periodLabel'] ?? ''); ?></strong>
				</p>
			</div>
			<div class="snk-card__header-actions">
				<?php if (!empty($report['byCategory'])): ?>
					<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.brReport', ['format' => 'csv', 'periodId' => $_['periodId'] ?? 0])); ?>"><?php p($l->t('Download CSV')); ?></a>
					<a class="snk-btn" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.complimentaryExport', ['id' => $_['periodId'] ?? 0])); ?>"><?php p($l->t('Complimentary qty CSV')); ?></a>
				<?php endif; ?>
			</div>
		</header>
		<div class="snk-card__body">
	<h3 class="snk-h2"><?php p($l->t('By category')); ?></h3>
	<?php if (empty($report['byCategory'])): ?>
		<?php
		$icon = 'file-text';
		$title = $l->t('No consumption in this period.');
		$text = $l->t('Totals appear after people log snacks.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<div class="snk-table-wrap">
			<table class="snk-table">
			<thead><tr><th scope="col"><?php p($l->t('Category')); ?></th><th scope="col"><?php p($l->t('Qty')); ?></th><th scope="col"><?php p($l->t('EUR')); ?></th></tr></thead>
			<tbody>
			<?php foreach ($report['byCategory'] as $row): ?>
				<tr>
					<td><?php p($row['category']); ?></td>
					<td><?php p($row['qty']); ?></td>
					<td><?php p(number_format($row['eurCents']/100, 2, ',', '.')); ?> €</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
	<h3 class="snk-h2"><?php p($l->t('By item')); ?></h3>
	<?php if (empty($report['byItem'])): ?>
		<?php
		$icon = 'package';
		$title = $l->t('No items.');
		$text = $l->t('Item totals appear after people log snacks.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<div class="snk-table-wrap">
			<table class="snk-table">
			<thead><tr><th scope="col"><?php p($l->t('Item')); ?></th><th scope="col"><?php p($l->t('Category')); ?></th><th scope="col"><?php p($l->t('Qty')); ?></th><th scope="col"><?php p($l->t('EUR')); ?></th></tr></thead>
			<tbody>
			<?php foreach ($report['byItem'] as $row): ?>
				<tr>
					<td><?php p($row['itemName']); ?></td>
					<td><?php p($row['category']); ?></td>
					<td><?php p($row['qty']); ?></td>
					<td><?php p(number_format($row['eurCents']/100, 2, ',', '.')); ?> €</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
		</div>
	</article>
</section>
