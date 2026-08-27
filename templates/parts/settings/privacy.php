<?php
/**
 * Settings · privacy
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<form class="snk-form" data-snk-form="settings">
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
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>
