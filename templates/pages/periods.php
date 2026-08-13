<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ $open = $_['open'] ?? null; $periods = $_['periods'] ?? []; ?>
<section class="snk-section" aria-label="<?php p($l->t('Periods')); ?>">
	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Current period')); ?></h2>
				<p class="snk-card__lead"><?php p($l->t('Open and close payroll periods. One primary export — more under the disclosure.')); ?></p>
			</div>
		</header>
		<div class="snk-card__body">
	<?php if ($open): ?>
		<p><?php p($l->t('Open period')); ?>: <strong><?php p($open->getLabel()); ?></strong></p>
		<?php if (!empty($_['siteNote'])): ?>
			<p class="snk-muted" role="note"><?php p($l->t('User payroll totals include all sites this period. Line sheets respect the site filter.')); ?></p>
			<label class="snk-field" for="snk-payroll-site">
				<span><?php p($l->t('Payroll site filter')); ?></span>
				<select id="snk-payroll-site" aria-describedby="snk-payroll-site-help">
					<option value="all"><?php p($l->t('All sites')); ?></option>
					<?php foreach (($_['sites'] ?? []) as $site): ?>
						<option value="<?php p(is_object($site) ? $site->getId() : ($site['id'] ?? '')); ?>">
							<?php p(is_object($site) ? $site->getName() : ($site['name'] ?? '')); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<p id="snk-payroll-site-help" class="snk-muted"><?php p($l->t('Lines and site sheets follow this filter. User totals stay org-wide.')); ?></p>
		<?php endif; ?>
		<div class="snk-actions">
			<button type="button" class="snk-btn snk-btn--primary" data-snk-action="payroll" data-period-id="<?php p($open->getId()); ?>"><?php p($l->t('Download payroll package')); ?></button>
			<details class="snk-details">
				<summary><?php p($l->t('More exports')); ?></summary>
				<div class="snk-actions">
					<button type="button" class="snk-btn" data-snk-action="hospitality-export" data-period-id="<?php p($open->getId()); ?>"><?php p($l->t('Hospitality CSV')); ?></button>
					<a class="snk-btn" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.complimentaryExport', ['id' => $open->getId()])); ?>"><?php p($l->t('Complimentary CSV')); ?></a>
					<a class="snk-btn" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.brReport')); ?>"><?php p($l->t('BR report')); ?></a>
				</div>
			</details>
			<button type="button" class="snk-btn snk-btn--danger" data-snk-action="close-period" data-period-id="<?php p($open->getId()); ?>"><?php p($l->t('Close period')); ?></button>
		</div>
	<?php else: ?>
		<div class="snk-callout" role="status">
			<p><?php p($l->t('No open period. Logging is locked until you open the next period.')); ?></p>
			<button type="button" class="snk-btn snk-btn--primary" data-snk-action="open-next-period"><?php p($l->t('Open next period')); ?></button>
		</div>
	<?php endif; ?>
		</div>
	</article>

	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('History')); ?></h2>
			</div>
		</header>
		<div class="snk-card__body">
	<?php if (empty($periods)): ?>
		<?php
		$icon = 'calendar-range';
		$title = $l->t('No periods yet.');
		$text = $l->t('Open the next period to start logging.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<div class="snk-table-wrap">
			<table class="snk-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('State')); ?></th>
					<th scope="col"><?php p($l->t('Handed to HR')); ?></th>
					<th scope="col"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($periods as $p): ?>
				<tr>
					<td><?php p($p->getLabel()); ?></td>
					<td><?php p($p->getState() === 'closed' ? $l->t('Closed') : $l->t('Open')); ?></td>
					<td><?php p($p->getHandedToHrAt() ? $p->getHandedToHrAt()->format('Y-m-d H:i') : '—'); ?></td>
					<td class="snk-actions">
						<button type="button" class="snk-btn snk-btn--primary" data-snk-action="payroll" data-period-id="<?php p($p->getId()); ?>"><?php p($l->t('Payroll')); ?></button>
						<?php if ($p->getState() === 'closed'): ?>
							<button type="button" class="snk-btn" data-snk-action="reopen-period" data-period-id="<?php p($p->getId()); ?>"><?php p($l->t('Reopen')); ?></button>
							<button type="button" class="snk-btn" data-snk-action="handed-hr" data-period-id="<?php p($p->getId()); ?>"><?php p($l->t('Handed to HR')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
		</div>
	</article>
	<dialog id="snk-reopen-dialog" class="snk-dialog" aria-labelledby="snk-reopen-title">
		<form method="dialog" data-snk-form="reopen-period">
			<h2 id="snk-reopen-title" class="snk-h2"><?php p($l->t('Reopen period')); ?></h2>
			<input type="hidden" name="periodId" id="snk-reopen-period-id" />
			<label class="snk-field" for="snk-reopen-reason">
				<span><?php p($l->t('Reason (min 3 characters)')); ?></span>
				<input id="snk-reopen-reason" name="reason" type="text" required minlength="3" maxlength="500" autocomplete="off" />
			</label>
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--primary" value="confirm"><?php p($l->t('Reopen')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>
	<dialog id="snk-close-dialog" class="snk-dialog" aria-labelledby="snk-close-title">
		<form method="dialog" data-snk-form="close-period-confirm">
			<h2 id="snk-close-title" class="snk-h2"><?php p($l->t('Close period anyway?')); ?></h2>
			<input type="hidden" name="periodId" id="snk-close-period-id" />
			<p id="snk-close-warnings" class="snk-muted" role="status"></p>
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--danger" value="confirm"><?php p($l->t('Close anyway')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>
</section>
