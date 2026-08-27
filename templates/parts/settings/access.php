<?php
/**
 * Settings · access — who may open SnackCheck (door policy).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<div class="snk-callout snk-callout--info" role="note" aria-labelledby="snk-access-door-title">
	<p id="snk-access-door-title"><strong><?php p($l->t('Who may open the app — not who runs the kitchen.')); ?></strong></p>
	<p class="snk-callout__text"><?php p($l->t('People here may open SnackCheck. Kitchen managers and app admins are separate. Turn on Restricted only after you have added at least one allowed user or group — otherwise you can lock yourself out.')); ?></p>
</div>

<form class="snk-form snk-form--settings" data-snk-form="settings">
	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Access mode')); ?></legend>
		<label class="snk-field" for="snk-access-mode">
			<span class="snk-sr-only"><?php p($l->t('Access mode')); ?></span>
			<select id="snk-access-mode" name="accessMode" class="snk-select">
				<option value="open" <?php if (($s['accessMode'] ?? '') === 'open') p('selected'); ?>><?php p($l->t('Open (all users)')); ?></option>
				<option value="listed" <?php if (($s['accessMode'] ?? '') === 'listed') p('selected'); ?>><?php p($l->t('Restricted (listed users/groups)')); ?></option>
			</select>
		</label>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Find people')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Tap Add… on a list below, then type a name here. Results go into the list you activated.')); ?></p>
		<div class="snk-chip-search snk-chip-search--settings">
			<label class="snk-field" for="snk-access-user-search">
				<span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
				<input id="snk-access-user-search" class="snk-input" type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-access-user-results" placeholder="<?php p($l->t('Type a name…')); ?>" />
			</label>
			<ul id="snk-access-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
		</div>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Who may open the app')); ?></legend>
		<div class="snk-field" role="group" aria-labelledby="snk-access-users-label">
			<span class="snk-field__label" id="snk-access-users-label"><?php p($l->t('Allowed users')); ?></span>
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
		</div>
		<div class="snk-field" role="group" aria-labelledby="snk-access-groups-label">
			<span class="snk-field__label" id="snk-access-groups-label"><?php p($l->t('Allowed groups')); ?></span>
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
		</div>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('App admins')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('App admins can change these settings and manage kitchens. Delegate carefully.')); ?></p>
		<div class="snk-field" role="group" aria-labelledby="snk-app-admins-label">
			<span class="snk-field__label" id="snk-app-admins-label"><?php p($l->t('App admins')); ?></span>
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
		</div>
	</fieldset>

	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
	</div>
</form>
