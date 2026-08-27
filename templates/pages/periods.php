<?php
/**
 * Current / history payroll periods — Bachus: status → export → (optional kitchen) → danger.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
use OCA\SnackCheck\Support\PeriodDisplay;

$open = $_['open'] ?? null;
$periods = $_['periods'] ?? [];
$sites = $_['sites'] ?? [];
$multiSiteFilter = !empty($_['siteNote']) && is_countable($sites) && count($sites) > 0;
?>
<section class="snk-section" aria-label="<?php p($l->t('Periods')); ?>">
	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Current period')); ?></h2>
				<p class="snk-card__lead"><?php p($l->t('Download payroll, then close when HR is ready.')); ?></p>
			</div>
		</header>
		<div class="snk-card__body">
	<?php if ($open): ?>
		<div class="snk-period-panel" data-snk-period-panel>
			<div class="snk-period-panel__status" role="status">
				<span class="snk-badge snk-badge--ok"><?php p($l->t('Open')); ?></span>
				<strong class="snk-period-panel__label"><?php p(PeriodDisplay::format((string)$open->getLabel())); ?></strong>
			</div>

			<?php if ($multiSiteFilter): ?>
				<div class="snk-period-panel__filter">
					<span class="snk-period-panel__filter-label" id="snk-payroll-site-label"><?php p($l->t('Line sheets for')); ?></span>
					<nav class="snk-filter-bar"
						data-snk-payroll-site-filters
						role="radiogroup"
						aria-labelledby="snk-payroll-site-label"
						aria-describedby="snk-payroll-site-help">
						<button type="button"
							class="snk-filter snk-filter--active"
							data-snk-payroll-site="all"
							aria-checked="true"
							role="radio"
							tabindex="0"><?php p($l->t('All kitchens')); ?></button>
						<?php foreach ($sites as $site):
							$sid = (string)(is_object($site) ? $site->getId() : ($site['id'] ?? ''));
							$sname = (string)(is_object($site) ? $site->getName() : ($site['name'] ?? ''));
							if ($sid === '') {
								continue;
							}
							?>
							<button type="button"
								class="snk-filter"
								data-snk-payroll-site="<?php p($sid); ?>"
								aria-checked="false"
								role="radio"
								tabindex="-1"><?php p($sname); ?></button>
						<?php endforeach; ?>
					</nav>
					<input type="hidden" id="snk-payroll-site" value="all" />
					<p id="snk-payroll-site-help" class="snk-muted snk-period-panel__hint">
						<?php p($l->t('User payroll totals always include every kitchen. This only narrows line sheets.')); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="snk-period-panel__primary snk-actions">
				<button type="button"
					class="snk-btn snk-btn--primary"
					data-snk-action="payroll"
					data-period-id="<?php p($open->getId()); ?>"><?php p($l->t('Download payroll package')); ?></button>
			</div>

			<details class="snk-details snk-period-panel__more">
				<summary><?php p($l->t('More exports')); ?></summary>
				<div class="snk-actions">
					<button type="button" class="snk-btn" data-snk-action="hospitality-export" data-period-id="<?php p($open->getId()); ?>"><?php p($l->t('Hospitality CSV')); ?></button>
					<a class="snk-btn" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.complimentaryExport', ['id' => $open->getId()])); ?>"><?php p($l->t('Complimentary CSV')); ?></a>
					<a class="snk-btn" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.brReport')); ?>"><?php p($l->t('BR report')); ?></a>
				</div>
			</details>

			<div class="snk-period-panel__danger">
				<button type="button"
					class="snk-btn snk-btn--danger"
					data-snk-action="close-period"
					data-period-id="<?php p($open->getId()); ?>"><?php p($l->t('Close period')); ?></button>
			</div>
		</div>
	<?php else: ?>
		<div class="snk-callout snk-callout--warn" role="status">
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
				<p class="snk-card__lead"><?php p($l->t('Earlier periods — download again or reopen with a reason.')); ?></p>
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
			<?php foreach ($periods as $p):
				$isOpen = $p->getState() === 'open';
				$displayLabel = PeriodDisplay::format((string)$p->getLabel());
				?>
				<tr<?php if ($isOpen) { ?> class="snk-period-row--open"<?php } ?>>
					<td>
						<span class="snk-period-row__label"><?php p($displayLabel); ?></span>
						<?php if ($displayLabel !== (string)$p->getLabel()): ?>
							<span class="snk-sr-only"><?php p($l->t('Internal id: %s', [(string)$p->getLabel()])); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ($isOpen): ?>
							<span class="snk-badge snk-badge--ok"><?php p($l->t('Open')); ?></span>
						<?php else: ?>
							<span class="snk-badge snk-badge--muted"><?php p($l->t('Closed')); ?></span>
						<?php endif; ?>
					</td>
					<td><?php p($p->getHandedToHrAt() ? $p->getHandedToHrAt()->format('Y-m-d H:i') : '—'); ?></td>
					<td>
						<div class="snk-actions snk-period-row__actions">
							<button type="button" class="snk-btn snk-btn--primary" data-snk-action="payroll" data-period-id="<?php p($p->getId()); ?>"><?php p($l->t('Payroll')); ?></button>
							<?php if (!$isOpen): ?>
								<details class="snk-row-more">
									<summary><?php p($l->t('More')); ?></summary>
									<div class="snk-actions">
										<button type="button" class="snk-btn" data-snk-action="reopen-period" data-period-id="<?php p($p->getId()); ?>"><?php p($l->t('Reopen')); ?></button>
										<button type="button" class="snk-btn" data-snk-action="handed-hr" data-period-id="<?php p($p->getId()); ?>"><?php p($l->t('Handed to HR')); ?></button>
									</div>
								</details>
							<?php endif; ?>
						</div>
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
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" class="snk-btn snk-btn--primary" value="confirm"><?php p($l->t('Reopen')); ?></button>
			</div>
		</form>
	</dialog>
	<dialog id="snk-close-dialog" class="snk-dialog" aria-labelledby="snk-close-title">
		<form method="dialog" data-snk-form="close-period-confirm">
			<h2 id="snk-close-title" class="snk-h2"><?php p($l->t('Close period anyway?')); ?></h2>
			<input type="hidden" name="periodId" id="snk-close-period-id" />
			<p id="snk-close-warnings" class="snk-muted" role="status"></p>
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" class="snk-btn snk-btn--danger" value="confirm"><?php p($l->t('Close anyway')); ?></button>
			</div>
		</form>
	</dialog>
</section>
