<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="snk-card snk-section" aria-labelledby="snk-xfer-export-h" id="snk-transfer-export-card">
	<header class="snk-section__header">
		<div>
			<h2 id="snk-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="snk-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="snk-callout snk-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="snk-transfer-export-form" class="snk-form" novalidate>
		<div class="snk-form-row">
			<label for="snk-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="snk-input-group">
				<input type="password" id="snk-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="snk-transfer-passphrase-hint snk-transfer-passphrase-strength">
				<button type="button" class="snk-btn snk-btn--ghost" id="snk-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="snk-btn snk-btn--secondary" id="snk-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="snk-form-row__hint" id="snk-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="snk-form-row__hint snk-transfer-strength" id="snk-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="snk-actions">
			<button type="submit" class="snk-btn snk-btn--primary" id="snk-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="snk-card snk-section" aria-labelledby="snk-xfer-import-h" id="snk-transfer-import-card">
	<header class="snk-section__header">
		<div>
			<h2 id="snk-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="snk-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="snk-transfer-last" id="snk-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="snk-transfer-import-form" class="snk-form" novalidate>
		<fieldset class="snk-form-row snk-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="snk-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="snk-radio snk-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="snk-form-row__hint" id="snk-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="snk-form-row">
			<label for="snk-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="snk-transfer-file" name="file" accept=".nctransfer,application/json" class="snk-file-input">
		</div>
		<div class="snk-form-row">
			<label for="snk-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="snk-input-group">
				<input type="password" id="snk-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="snk-btn snk-btn--ghost" id="snk-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="snk-transfer-import-status" class="snk-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="snk-transfer-report" class="snk-dialog snk-transfer-report" aria-labelledby="snk-transfer-report-h">
	<div class="snk-dialog__body">
		<h3 id="snk-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="snk-transfer-report-verdict" class="snk-transfer-report__verdict"></p>
		<p id="snk-transfer-report-file" class="snk-form-row__hint"></p>
		<div id="snk-transfer-report-groups"></div>
	</div>
	<footer class="snk-dialog__footer">
		<div id="snk-transfer-replace-confirm" class="snk-form-row" hidden>
			<label for="snk-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="snk-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="snk-actions">
			<button type="button" class="snk-btn snk-btn--secondary" id="snk-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="snk-btn snk-btn--primary" id="snk-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="snk-transfer-progress" class="snk-dialog" aria-labelledby="snk-transfer-progress-h" aria-live="polite">
	<div class="snk-dialog__body">
		<h3 id="snk-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="snk-transfer-progress-bar" max="100" value="0"></progress>
		<p id="snk-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="snk-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="snk-transfer-result-live" class="snk-visually-hidden" role="status" aria-live="polite"></div>
