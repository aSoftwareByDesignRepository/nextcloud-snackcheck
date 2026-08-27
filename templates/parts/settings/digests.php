<?php
/**
 * Settings · digests
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<form class="snk-form" data-snk-form="settings">
			<input type="hidden" name="personalDigestEnabled" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="personalDigestEnabled" id="snk-digest-personal" value="1" <?php if (!empty($s['personalDigestEnabled'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-digest-personal">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Personal pre-close digest')); ?></span>
				</label>
			</div>
			<label class="snk-field"><span><?php p($l->t('Days before period end')); ?></span>
				<input name="personalDigestDaysBefore" class="snk-input" type="number" min="1" max="14" value="<?php p($s['personalDigestDaysBefore'] ?? 3); ?>" />
			</label>
			<input type="hidden" name="personalDigestSkipZero" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="personalDigestSkipZero" id="snk-digest-skip-zero" value="1" <?php if (!array_key_exists('personalDigestSkipZero', $s) || !empty($s['personalDigestSkipZero'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-digest-skip-zero">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Skip digests when nothing to deduct (€0)')); ?></span>
				</label>
			</div>
			<input type="hidden" name="weeklyTopUpEmail" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="weeklyTopUpEmail" id="snk-digest-weekly" value="1" <?php if (!empty($s['weeklyTopUpEmail'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-digest-weekly">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Weekly top-up email')); ?></span>
				</label>
			</div>
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>
