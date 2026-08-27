<?php
/**
 * Settings · unlock
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<form class="snk-form" data-snk-form="unlock-pin">
			<label class="snk-field"><span><?php p($l->t('User')); ?></span>
				<?php
				$name = 'userId';
				$value = '';
				$picker = 'users';
				$single = true;
				$required = true;
				$listLabel = $l->t('PIN user');
				$chips = [];
				$fieldId = 'snk-unlock-pin-user';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</label>
			<label class="snk-field"><span><?php p($l->t('PIN (4–8 digits)')); ?></span>
				<input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" autocomplete="new-password" required />
			</label>
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save PIN')); ?></button>
		</form>
		<form class="snk-form" data-snk-form="unlock-qr">
			<label class="snk-field"><span><?php p($l->t('User')); ?></span>
				<?php
				$name = 'userId';
				$value = '';
				$picker = 'users';
				$single = true;
				$required = true;
				$listLabel = $l->t('QR user');
				$chips = [];
				$fieldId = 'snk-unlock-qr-user';
				include __DIR__ . '/../snk-chip-field.php';
				?>
			</label>
			<label class="snk-field"><span><?php p($l->t('QR payload')); ?></span>
				<input name="payload" autocomplete="off" required />
			</label>
			<button type="submit" class="snk-btn"><?php p($l->t('Save QR')); ?></button>
		</form>
		<div class="snk-chip-search">
			<label class="snk-field"><span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
				<input type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-unlock-user-results" />
			</label>
			<ul id="snk-unlock-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
		</div>
