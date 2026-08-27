<?php
/**
 * Settings · privacy
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$s = $_['settings'] ?? [];
?>
<div class="snk-callout snk-callout--info" role="note">
	<p><?php p($l->t('When privacy mode is on, kitchen managers see period totals only — not who tapped which snack. Payroll exports stay available for entitled admins.')); ?></p>
</div>

<form class="snk-form snk-form--settings" data-snk-form="settings">
	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Privacy')); ?></legend>
		<input type="hidden" name="privacyTotalsOnly" value="0" />
		<div class="snk-switch-field">
			<input class="snk-switch-field__input" type="checkbox" role="switch" name="privacyTotalsOnly" id="snk-privacy-totals" value="1" <?php if (!empty($s['privacyTotalsOnly'])) { ?>checked<?php } ?> />
			<label class="snk-switch-field__label" for="snk-privacy-totals">
				<span class="snk-switch-field__track" aria-hidden="true"></span>
				<span class="snk-switch-field__text"><?php p($l->t('Totals only')); ?>
					<span class="snk-switch-field__hint"><?php p($l->t('Itemized lines hidden by privacy mode.')); ?></span>
				</span>
			</label>
		</div>
	</fieldset>
	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
	</div>
</form>
