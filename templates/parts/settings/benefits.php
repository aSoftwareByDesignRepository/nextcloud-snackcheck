<?php
/**
 * Settings · benefits
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
<?php
		$subsidyCents = (int)($s['subsidyAllowanceCents'] ?? 0);
		$subsidyEuro = number_format($subsidyCents / 100, 2, '.', '');
		?>
		<form class="snk-form" data-snk-form="settings" id="snk-benefits-form">
			<label class="snk-field"><span><?php p($l->t('Monthly subsidy (€)')); ?></span>
				<input name="subsidyAllowanceEuro" class="snk-input" type="number" min="0" step="0.01" inputmode="decimal" value="<?php p($subsidyEuro); ?>" />
			</label>
			<input type="hidden" name="hospitalityEnabled" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="hospitalityEnabled" id="snk-hosp-enabled" value="1" <?php if (!empty($s['hospitalityEnabled'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-hosp-enabled">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Hospitality')); ?>
						<span class="snk-switch-field__hint"><?php p($l->t('Company treats and allowlists.')); ?></span>
					</span>
				</label>
			</div>
			<div id="snk-hosp-fields" <?php if (empty($s['hospitalityEnabled'])) { ?>hidden<?php } ?>>
				<label class="snk-field"><span><?php p($l->t('Company user')); ?></span>
					<?php
					$name = 'hospitalityCompanyUserId';
					$value = (string)($s['hospitalityCompanyUserId'] ?? '');
					$picker = 'users';
					$single = true;
					$required = false;
					$listLabel = $l->t('Company user');
					$chips = $_['hospCompanyChips'] ?? [];
					$fieldId = 'snk-hosp-company';
					include __DIR__ . '/../snk-chip-field.php';
					?>
				</label>
				<label class="snk-field"><span><?php p($l->t('Allowlist')); ?></span>
					<?php
					$name = 'hospitalityAllowedUserIds';
					$value = implode(',', $_['hospAllowlist'] ?? []);
					$picker = 'users';
					$single = false;
					$required = false;
					$listLabel = $l->t('Hospitality allowlist');
					$chips = $_['hospAllowChips'] ?? [];
					$fieldId = 'snk-hosp-allow';
					include __DIR__ . '/../snk-chip-field.php';
					?>
				</label>
				<div class="snk-chip-search">
					<label class="snk-field"><span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
						<input type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-benefits-user-results" />
					</label>
					<ul id="snk-benefits-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
				</div>
				<p id="snk-hosp-save-hint" class="snk-muted" <?php
					$hospOn = !empty($s['hospitalityEnabled']);
					$hospOk = trim((string)($s['hospitalityCompanyUserId'] ?? '')) !== '' && !empty($_['hospAllowlist']);
					if (!$hospOn || $hospOk) {
						p(' hidden');
					}
				?>><?php p($l->t('Add a company user and allowlist, or Save will leave Hospitality off.')); ?></p>
			</div>
			<input type="hidden" name="multiSiteEnabled" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="multiSiteEnabled" id="snk-multisite-enabled" value="1" <?php if (!empty($s['multiSiteEnabled'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-multisite-enabled">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Multi-site')); ?>
						<span class="snk-switch-field__hint"><?php p($l->t('Kitchens and who manages them.')); ?></span>
					</span>
				</label>
			</div>
			<button type="submit" class="snk-btn snk-btn--primary" id="snk-benefits-save"><?php p($l->t('Save')); ?></button>
		</form>

<script>
(function () {
	const en = document.getElementById('snk-hosp-enabled');
	const fields = document.getElementById('snk-hosp-fields');
	const hint = document.getElementById('snk-hosp-save-hint');
	function hospComplete() {
		if (!fields) return false;
		const company = fields.querySelector('[name="hospitalityCompanyUserId"]');
		const allow = fields.querySelector('[name="hospitalityAllowedUserIds"]');
		return !!(company && company.value.trim() && allow && allow.value.trim());
	}
	function sync() {
		if (!en || !fields) return;
		const on = !!en.checked;
		fields.hidden = !on;
		const ok = hospComplete();
		const save = document.getElementById('snk-benefits-save');
		// Bachus UX-30: Save stays clickable — incomplete hospitality auto-clears on submit.
		if (save) {
			save.disabled = false;
			save.setAttribute('aria-disabled', 'false');
		}
		if (hint) {
			hint.hidden = !on || ok;
		}
	}
	if (en) { en.addEventListener('change', sync); }
	if (fields) {
		fields.querySelectorAll('input').forEach(function (el) {
			el.addEventListener('input', sync);
			el.addEventListener('change', sync);
		});
		const mo = new MutationObserver(sync);
		mo.observe(fields, { attributes: true, subtree: true, attributeFilter: ['value'] });
	}
	document.addEventListener('snk-chips-changed', sync);
	sync();
})();
</script>
