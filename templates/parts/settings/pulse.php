<?php
/**
 * Settings · kitchen overview (restock / popularity windows)
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$s = $_['settings'] ?? [];
$pace = (int)($s['paceWindowDays'] ?? 14);
$horizon = (int)($s['restockHorizonDays'] ?? 3);
?>
<form class="snk-form snk-form--settings" data-snk-form="settings">
	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Kitchen overview')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('How many days to use for restock tips and popular snacks.')); ?></p>

		<label class="snk-field" for="snk-pace-window">
			<span><?php p($l->t('Days of recent snacks')); ?></span>
			<select id="snk-pace-window" name="paceWindowDays" class="snk-select" aria-describedby="snk-pace-window-hint">
				<?php foreach ([7, 14, 30] as $d): ?>
					<option value="<?php p($d); ?>" <?php if ($pace === $d) {
						p('selected');
					} ?>><?php p($d); ?></option>
				<?php endforeach; ?>
			</select>
			<span id="snk-pace-window-hint" class="snk-field__hint snk-muted"><?php p($l->t('Used for "What\'s popular" — how far back snack logs count.')); ?></span>
		</label>

		<label class="snk-field" for="snk-restock-horizon">
			<span><?php p($l->t('Warn if stock lasts under (days)')); ?></span>
			<input id="snk-restock-horizon" class="snk-input" name="restockHorizonDays" type="number" min="1" max="30" value="<?php p($horizon); ?>" aria-describedby="snk-restock-horizon-hint" />
			<span id="snk-restock-horizon-hint" class="snk-field__hint snk-muted"><?php p($l->t('Items appear on the restock list when stock would run out within this many days.')); ?></span>
		</label>
	</fieldset>
	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
	</div>
</form>
