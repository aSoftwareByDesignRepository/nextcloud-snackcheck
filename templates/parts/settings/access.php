<?php
/**
 * Settings · access
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<form class="snk-form" data-snk-form="settings">
			<label class="snk-field"><span><?php p($l->t('Access mode')); ?></span>
				<select name="accessMode">
					<option value="open" <?php if (($s['accessMode'] ?? '') === 'open') p('selected'); ?>><?php p($l->t('Open (all users)')); ?></option>
					<option value="listed" <?php if (($s['accessMode'] ?? '') === 'listed') p('selected'); ?>><?php p($l->t('Restricted (listed users/groups)')); ?></option>
				</select>
			</label>
			<label class="snk-field"><span><?php p($l->t('Allowed users')); ?></span>
				<?php
				$name = 'accessUsers';
				$value = implode(',', $s['accessUsers'] ?? []);
				$picker = 'users';
				$single = false;
				$required = false;
				$listLabel = $l->t('Allowed users');
				$chips = $_['accessUserChips'] ?? [];
				$fieldId = 'snk-access-users';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</label>
			<label class="snk-field"><span><?php p($l->t('Allowed groups')); ?></span>
				<?php
				$name = 'accessGroups';
				$value = implode(',', $s['accessGroups'] ?? []);
				$picker = 'groups';
				$single = false;
				$required = false;
				$listLabel = $l->t('Allowed groups');
				$chips = $_['accessGroupChips'] ?? [];
				$fieldId = 'snk-access-groups';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</label>
			<label class="snk-field"><span><?php p($l->t('App admins')); ?></span>
				<?php
				$name = 'appAdmins';
				$value = implode(',', $s['appAdmins'] ?? []);
				$picker = 'users';
				$single = false;
				$required = false;
				$listLabel = $l->t('App admins');
				$chips = $_['appAdminChips'] ?? [];
				$fieldId = 'snk-app-admins';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</label>
			<div class="snk-chip-search">
				<label class="snk-field"><span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
					<input type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-access-user-results" />
				</label>
				<ul id="snk-access-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
			</div>
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>
