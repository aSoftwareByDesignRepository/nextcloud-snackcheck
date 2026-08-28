<?php
/**
 * Settings · access — who may open SnackCheck (door policy).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
$accessListed = ($s['accessMode'] ?? 'open') === 'listed';
?>
<div class="snk-callout snk-callout--info" role="note" aria-labelledby="snk-access-door-title">
	<p id="snk-access-door-title"><strong><?php p($l->t('Who may open the app — not who runs the kitchen.')); ?></strong></p>
	<p class="snk-callout__text"><?php p($l->t('This page controls the front door only. Kitchen managers and site roles are configured elsewhere.')); ?></p>
</div>

<form class="snk-form snk-form--settings" data-snk-form="settings" id="snk-access-form">
	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Door access')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Choose whether every signed-in user can open SnackCheck, or only people you list below.')); ?></p>
		<label class="snk-field" for="snk-access-mode">
			<span class="snk-field__label"><?php p($l->t('Access mode')); ?></span>
			<select id="snk-access-mode" name="accessMode" class="snk-select">
				<option value="open" <?php if (!$accessListed) p('selected'); ?>><?php p($l->t('Open (all users)')); ?></option>
				<option value="listed" <?php if ($accessListed) p('selected'); ?>><?php p($l->t('Restricted (listed users/groups)')); ?></option>
			</select>
		</label>
		<p id="snk-access-open-hint" class="snk-muted snk-settings-block__hint"<?php if ($accessListed) { ?> hidden<?php } ?>><?php p($l->t('Everyone signed in to this Nextcloud can open SnackCheck.')); ?></p>
	</fieldset>

	<fieldset id="snk-access-restricted" class="snk-settings-block"<?php if (!$accessListed) { ?> hidden<?php } ?>>
		<legend class="snk-settings-block__legend"><?php p($l->t('Allowed people')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Add at least one user or group. Search in each box — results are added to that list only.')); ?></p>
		<p id="snk-access-restricted-warn" class="snk-callout snk-callout--warn" role="status" hidden><?php p($l->t('Add at least one allowed user or group before saving Restricted mode.')); ?></p>
		<div class="snk-access-roster">
			<div class="snk-access-roster__item">
				<h3 class="snk-access-roster__title" id="snk-access-users-heading"><?php p($l->t('Allowed users')); ?></h3>
				<?php
				$name = 'accessUsers';
				$value = implode(',', $s['accessUsers'] ?? []);
				$picker = 'users';
				$single = false;
				$required = false;
				$inlineSearch = true;
				$listLabel = $l->t('Allowed users');
				$chips = $_['accessUserChips'] ?? [];
				$fieldId = 'snk-access-users';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</div>
			<div class="snk-access-roster__item">
				<h3 class="snk-access-roster__title" id="snk-access-groups-heading"><?php p($l->t('Allowed groups')); ?></h3>
				<?php
				$name = 'accessGroups';
				$value = implode(',', $s['accessGroups'] ?? []);
				$picker = 'groups';
				$single = false;
				$required = false;
				$inlineSearch = true;
				$listLabel = $l->t('Allowed groups');
				$chips = $_['accessGroupChips'] ?? [];
				$fieldId = 'snk-access-groups';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</div>
		</div>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('App admins')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('App admins can change these settings and manage kitchens. They always pass the door, even in Restricted mode.')); ?></p>
		<h3 class="snk-access-roster__title" id="snk-app-admins-heading"><?php p($l->t('App admins')); ?></h3>
		<?php
		$name = 'appAdmins';
		$value = implode(',', $s['appAdmins'] ?? []);
		$picker = 'users';
		$single = false;
		$required = false;
		$inlineSearch = true;
		$listLabel = $l->t('App admins');
		$chips = $_['appAdminChips'] ?? [];
		$fieldId = 'snk-app-admins';
		include __DIR__ . '/../snk-chip-field.php';
		?>
	</fieldset>

	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary" id="snk-access-save"><?php p($l->t('Save')); ?></button>
	</div>
</form>

<script>
(function () {
	const mode = document.getElementById('snk-access-mode');
	const restricted = document.getElementById('snk-access-restricted');
	const openHint = document.getElementById('snk-access-open-hint');
	const warn = document.getElementById('snk-access-restricted-warn');
	function restrictedComplete() {
		const users = document.getElementById('snk-access-users');
		const groups = document.getElementById('snk-access-groups');
		return !!((users && users.value.trim()) || (groups && groups.value.trim()));
	}
	function sync() {
		if (!mode) return;
		const listed = mode.value === 'listed';
		if (restricted) restricted.hidden = !listed;
		if (openHint) openHint.hidden = listed;
		if (warn) warn.hidden = !listed || restrictedComplete();
	}
	if (mode) mode.addEventListener('change', sync);
	document.addEventListener('snk-chips-changed', sync);
	sync();
})();
</script>
