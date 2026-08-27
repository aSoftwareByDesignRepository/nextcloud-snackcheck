<?php
/**
 * Settings · unlock PIN / QR for kitchen tablets
 *
 * Shared directory search feeds the single-user chip fields in the PIN and QR
 * forms. Secrets are never echoed after save (JS clears the inputs).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div class="snk-callout snk-callout--info" role="note" aria-labelledby="snk-unlock-secrets-title">
	<p id="snk-unlock-secrets-title"><strong><?php p($l->t('Unlock for kitchen tablets')); ?></strong></p>
	<p class="snk-callout__text"><?php p($l->t('Give someone a PIN and/or a QR sticker so they can unlock the fridge tablet. Never send these secrets in chat or email.')); ?></p>
</div>

<section class="snk-settings-block" aria-labelledby="snk-unlock-find-title">
	<h2 id="snk-unlock-find-title" class="snk-settings-block__legend"><?php p($l->t('Find people')); ?></h2>
	<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Tap Choose… under PIN or QR below, then type a name here.')); ?></p>
	<div class="snk-chip-search snk-chip-search--settings">
		<label class="snk-field" for="snk-unlock-user-search">
			<span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
			<input id="snk-unlock-user-search" class="snk-input" type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-unlock-user-results" placeholder="<?php p($l->t('Type a name…')); ?>" />
		</label>
		<ul id="snk-unlock-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
	</div>
</section>

<p id="snk-unlock-choice-lead" class="snk-unlock-choice-lead"><?php p($l->t('Choose how they unlock — PIN, QR code, or both.')); ?></p>

<div class="snk-settings-methods" role="group" aria-labelledby="snk-unlock-choice-lead">
	<form class="snk-form snk-form--settings snk-settings-block snk-settings-method snk-settings-method--pin" data-snk-form="unlock-pin" aria-labelledby="snk-unlock-pin-title">
		<h2 class="snk-settings-block__legend" id="snk-unlock-pin-title">
			<span class="snk-settings-method__tag" aria-hidden="true"><?php p($l->t('PIN')); ?></span>
			<?php p($l->t('Unlock with PIN')); ?>
		</h2>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('4–8 digits. They type this on the tablet keypad.')); ?></p>

		<div class="snk-field" role="group" aria-labelledby="snk-unlock-pin-user-label">
			<span class="snk-field__label" id="snk-unlock-pin-user-label"><?php p($l->t('Person')); ?></span>
			<?php
			$name = 'userId';
			$value = '';
			$picker = 'users';
			$single = true;
			$required = true;
			$listLabel = $l->t('Person for PIN');
			$chips = [];
			$fieldId = 'snk-unlock-pin-user';
			include __DIR__ . '/../snk-chip-field.php';
			?>
		</div>

		<label class="snk-field" for="snk-unlock-pin">
			<span><?php p($l->t('PIN (4–8 digits)')); ?></span>
			<input
				id="snk-unlock-pin"
				class="snk-input"
				name="pin"
				type="password"
				inputmode="numeric"
				pattern="[0-9]{4,8}"
				minlength="4"
				maxlength="8"
				autocomplete="new-password"
				spellcheck="false"
				required
				aria-describedby="snk-unlock-pin-hint"
			/>
			<span id="snk-unlock-pin-hint" class="snk-field__hint snk-muted"><?php p($l->t('Only digits. Each PIN can belong to only one person.')); ?></span>
		</label>

		<div class="snk-form-actions">
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save PIN')); ?></button>
		</div>
	</form>

	<form class="snk-form snk-form--settings snk-settings-block snk-settings-method snk-settings-method--qr" data-snk-form="unlock-qr" aria-labelledby="snk-unlock-qr-title">
		<h2 class="snk-settings-block__legend" id="snk-unlock-qr-title">
			<span class="snk-settings-method__tag" aria-hidden="true"><?php p($l->t('QR')); ?></span>
			<?php p($l->t('Unlock with QR code')); ?>
		</h2>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Put this text into a QR sticker. The tablet scans it to unlock.')); ?></p>

		<div class="snk-field" role="group" aria-labelledby="snk-unlock-qr-user-label">
			<span class="snk-field__label" id="snk-unlock-qr-user-label"><?php p($l->t('Person')); ?></span>
			<?php
			$name = 'userId';
			$value = '';
			$picker = 'users';
			$single = true;
			$required = true;
			$listLabel = $l->t('Person for QR');
			$chips = [];
			$fieldId = 'snk-unlock-qr-user';
			include __DIR__ . '/../snk-chip-field.php';
			?>
		</div>

		<label class="snk-field" for="snk-unlock-qr">
			<span><?php p($l->t('Text in the QR code')); ?></span>
			<input
				id="snk-unlock-qr"
				class="snk-input"
				name="payload"
				type="text"
				autocomplete="off"
				spellcheck="false"
				required
				minlength="4"
				maxlength="256"
				aria-describedby="snk-unlock-qr-hint"
			/>
			<span id="snk-unlock-qr-hint" class="snk-field__hint snk-muted"><?php p($l->t('At least 4 characters. Keep it secret — anyone with this text can unlock as that person.')); ?></span>
		</label>

		<div class="snk-form-actions">
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save QR')); ?></button>
		</div>
	</form>
</div>
