<?php
/**
 * Settings · pulse
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<form class="snk-form" data-snk-form="settings">
			<label class="snk-field"><span><?php p($l->t('Pace window (days)')); ?></span>
				<select name="paceWindowDays">
					<?php foreach ([7,14,30] as $d): ?>
						<option value="<?php p($d); ?>" <?php if ((int)($s['paceWindowDays'] ?? 14) === $d) p('selected'); ?>><?php p($d); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="snk-field"><span><?php p($l->t('Restock horizon (days)')); ?></span>
				<input name="restockHorizonDays" type="number" min="1" max="30" value="<?php p($s['restockHorizonDays'] ?? 3); ?>" />
			</label>
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>
