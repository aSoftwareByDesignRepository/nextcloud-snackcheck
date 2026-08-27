<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
use OCA\SnackCheck\Support\PeriodDisplay;
$period = $_['period'] ?? null;
?>
<section class="snk-section" aria-label="<?php p($l->t('Hospitality')); ?>">
	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Company treats')); ?></h2>
				<p class="snk-card__lead">
					<?php p($l->t('Company user')); ?>: <strong><?php p($_['companyUserDisplay'] ?? ($_['companyUserId'] ?: '—')); ?></strong>
					<?php if ($period): ?>
						· <?php p($l->t('Period')); ?>: <?php p(PeriodDisplay::format((string)$period->getLabel())); ?>
					<?php endif; ?>
				</p>
			</div>
		</header>
		<div class="snk-card__body">
	<?php if (!empty($_['periods'])): ?>
		<form class="snk-form" method="get" action="">
			<label class="snk-field" for="snk-hosp-period">
				<span><?php p($l->t('Period')); ?></span>
				<select id="snk-hosp-period" name="periodId" onchange="this.form.submit()">
					<?php foreach ($_['periods'] as $p): ?>
						<option value="<?php p($p->getId()); ?>" <?php if ($period && (int)$period->getId() === (int)$p->getId()) p('selected'); ?>>
							<?php p(PeriodDisplay::format((string)$p->getLabel())); ?> (<?php p($p->getState() === 'closed' ? $l->t('Closed') : $l->t('Open')); ?>)
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</form>
	<?php endif; ?>
	<?php if (!empty($_['allowlistDisplay'])): ?>
		<details class="snk-details">
			<summary><?php p($l->t('Who can book on company')); ?></summary>
			<p class="snk-muted"><?php p(implode(', ', $_['allowlistDisplay'])); ?></p>
		</details>
	<?php endif; ?>
	<?php if (!$period || empty($_['rows'])): ?>
		<?php
		$icon = 'coffee';
		$title = $l->t('No company hospitality bookings this period.');
		$text = $l->t('When colleagues book on company, lines appear here.');
		$actionsHtml = '<a class="snk-btn snk-btn--primary" href="'
			. htmlspecialchars($urlGenerator->linkToRoute('snackcheck.page.log'), ENT_QUOTES, 'UTF-8')
			. '">' . htmlspecialchars($l->t('Log a snack'), ENT_QUOTES, 'UTF-8') . '</a>'
			. '<a class="snk-btn" href="'
			. htmlspecialchars($urlGenerator->linkToRoute('snackcheck.page.settings', ['section' => 'benefits']), ENT_QUOTES, 'UTF-8')
			. '">' . htmlspecialchars($l->t('Open Benefits'), ENT_QUOTES, 'UTF-8') . '</a>';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<div class="snk-table-wrap">
			<table class="snk-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('When')); ?></th>
					<?php if (!empty($_['multiSite'])): ?>
						<th scope="col"><?php p($l->t('Site')); ?></th>
					<?php endif; ?>
					<th scope="col"><?php p($l->t('Actor')); ?></th>
					<th scope="col"><?php p($l->t('Item')); ?></th>
					<th scope="col"><?php p($l->t('Qty')); ?></th>
					<th scope="col"><?php p($l->t('Total')); ?></th>
					<th scope="col"><?php p($l->t('Reason')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($_['rows'] as $r): ?>
				<tr>
					<td><?php p($r['logged_at'] ?? ''); ?></td>
					<?php if (!empty($_['multiSite'])): ?>
						<td><?php p($r['site_name'] ?? $r['site_code'] ?? ''); ?></td>
					<?php endif; ?>
					<td><?php p($r['actor_display'] ?? $r['actor_uid'] ?? ''); ?></td>
					<td><?php p($r['item_name'] ?? ''); ?></td>
					<td><?php p($r['qty'] ?? ''); ?></td>
					<td><?php
						$cents = (int)($r['line_total_cents'] ?? 0);
						if ($cents === 0) {
							p($l->t('Free'));
						} else {
							p(number_format($cents / 100, 2, ',', '.') . ' €');
						}
					?></td>
					<td><?php p($r['reason'] ?? ''); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<div class="snk-actions">
			<?php
			$hospParams = ['id' => $period->getId()];
			if (!empty($_['exportSiteId'])) {
				$hospParams['siteId'] = (int)$_['exportSiteId'];
			}
			?>
			<a class="snk-btn snk-btn--primary" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.downloadHospitality', $hospParams)); ?>"><?php p($l->t('Download hospitality CSV')); ?></a>
		</div>
	<?php endif; ?>
		</div>
	</article>
</section>
