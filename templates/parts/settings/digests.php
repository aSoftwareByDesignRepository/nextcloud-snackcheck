<?php
/**
 * Settings · reminder emails
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$s = $_['settings'] ?? [];
?>
<form class="snk-form snk-form--settings" data-snk-form="settings">
	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Personal reminder emails')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Optional emails before month end so people can check My month.')); ?></p>
		<input type="hidden" name="personalDigestEnabled" value="0" />
		<div class="snk-switch-field">
			<input class="snk-switch-field__input" type="checkbox" role="switch" name="personalDigestEnabled" id="snk-digest-personal" value="1" <?php if (!empty($s['personalDigestEnabled'])) { ?>checked<?php } ?> />
			<label class="snk-switch-field__label" for="snk-digest-personal">
				<span class="snk-switch-field__track" aria-hidden="true"></span>
				<span class="snk-switch-field__text"><?php p($l->t('Reminder email before month end')); ?></span>
			</label>
		</div>
		<label class="snk-field" for="snk-digest-days">
			<span><?php p($l->t('Days before month end')); ?></span>
			<input id="snk-digest-days" name="personalDigestDaysBefore" class="snk-input" type="number" min="1" max="14" value="<?php p($s['personalDigestDaysBefore'] ?? 3); ?>" />
		</label>
		<input type="hidden" name="personalDigestSkipZero" value="0" />
		<div class="snk-switch-field">
			<input class="snk-switch-field__input" type="checkbox" role="switch" name="personalDigestSkipZero" id="snk-digest-skip-zero" value="1" <?php if (!array_key_exists('personalDigestSkipZero', $s) || !empty($s['personalDigestSkipZero'])) { ?>checked<?php } ?> />
			<label class="snk-switch-field__label" for="snk-digest-skip-zero">
				<span class="snk-switch-field__track" aria-hidden="true"></span>
				<span class="snk-switch-field__text"><?php p($l->t('Skip email when nothing to deduct (€0)')); ?></span>
			</label>
		</div>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Kitchen managers')); ?></legend>
		<input type="hidden" name="weeklyTopUpEmail" value="0" />
		<div class="snk-switch-field">
			<input class="snk-switch-field__input" type="checkbox" role="switch" name="weeklyTopUpEmail" id="snk-digest-weekly" value="1" <?php if (!empty($s['weeklyTopUpEmail'])) { ?>checked<?php } ?> />
			<label class="snk-switch-field__label" for="snk-digest-weekly">
				<span class="snk-switch-field__track" aria-hidden="true"></span>
				<span class="snk-switch-field__text"><?php p($l->t('Weekly restock email')); ?>
					<span class="snk-switch-field__hint"><?php p($l->t('Restock tips for kitchen managers.')); ?></span>
				</span>
			</label>
		</div>
	</fieldset>

	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
	</div>
</form>
