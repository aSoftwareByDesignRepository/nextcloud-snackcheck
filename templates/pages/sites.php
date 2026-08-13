<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<section class="snk-section" aria-label="<?php p($l->t('Sites')); ?>">
	<?php if (empty($_['siteRows'])): ?>
		<?php
		$icon = 'building-2';
		$title = $l->t('No sites yet.');
		$text = $l->t('Add a kitchen site below.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
	<ul class="snk-site-list">
		<?php foreach (($_['siteRows'] ?? []) as $site): ?>
			<li class="snk-card">
				<header class="snk-card__header">
					<div class="snk-card__header-text">
						<h2 class="snk-card__title"><?php p($site['name']); ?></h2>
						<p class="snk-card__lead">
							<span class="snk-muted">(<?php p($site['code']); ?>)</span>
							· <?php p(!empty($site['active']) ? $l->t('Active') : $l->t('Inactive')); ?>
							· <?php p($l->t('Managers')); ?>: <?php p($site['managers'] ? implode(', ', $site['managers']) : '—'); ?>
						</p>
					</div>
				</header>
				<div class="snk-card__body">
				<form class="snk-form" data-snk-form="site-update" data-site-id="<?php p($site['id']); ?>">
					<label class="snk-field">
						<span><?php p($l->t('Managers')); ?></span>
						<?php
						$name = 'managerUids';
						$value = implode(',', $site['managers'] ?? []);
						$picker = 'users';
						$single = false;
						$required = false;
						$listLabel = $l->t('Managers');
						$chips = $site['managerChips'] ?? [];
						$fieldId = 'snk-site-managers-' . (int)$site['id'];
						include __DIR__ . '/../parts/snk-chip-field.php';
						?>
					</label>
					<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save managers')); ?></button>
				</form>
				<div class="snk-actions">
					<?php if (!empty($site['active'])): ?>
						<?php if (($site['code'] ?? '') !== 'DEFAULT'): ?>
							<button type="button" class="snk-btn snk-btn--danger" data-snk-action="deactivate-site" data-site-id="<?php p($site['id']); ?>"><?php p($l->t('Deactivate')); ?></button>
						<?php else: ?>
							<p class="snk-muted"><?php p($l->t('Default site stays active.')); ?></p>
						<?php endif; ?>
					<?php else: ?>
						<button type="button" class="snk-btn snk-btn--primary" data-snk-action="activate-site" data-site-id="<?php p($site['id']); ?>"><?php p($l->t('Activate')); ?></button>
					<?php endif; ?>
				</div>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<div class="snk-chip-search">
		<label class="snk-field">
			<span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
			<input type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-sites-user-results" />
		</label>
		<ul id="snk-sites-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
	</div>
	<article class="snk-card">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p($l->t('Add site')); ?></h2>
				<p class="snk-card__lead"><?php p($l->t('Name and code. Pick managers with search — never type raw IDs.')); ?></p>
			</div>
		</header>
		<div class="snk-card__body">
	<form class="snk-form" data-snk-form="site-create" aria-label="<?php p($l->t('Add site')); ?>">
		<label class="snk-field">
			<span><?php p($l->t('Name')); ?></span>
			<input name="name" required maxlength="80" />
		</label>
		<label class="snk-field">
			<span><?php p($l->t('Code')); ?></span>
			<input name="code" required maxlength="40" pattern="[A-Za-z0-9_-]+" />
		</label>
		<label class="snk-field">
			<span><?php p($l->t('Managers')); ?></span>
			<?php
			$name = 'managerUids';
			$value = '';
			$picker = 'users';
			$single = false;
			$required = false;
			$listLabel = $l->t('Managers');
			$chips = [];
			$fieldId = 'snk-site-create-managers';
			include __DIR__ . '/../parts/snk-chip-field.php';
			?>
		</label>
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Create site')); ?></button>
	</form>
		</div>
	</article>
</section>
