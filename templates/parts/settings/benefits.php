<?php
/**
 * Settings · benefits & kitchens
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
$subsidyCents = (int)($s['subsidyAllowanceCents'] ?? 0);
$subsidyEuro = number_format($subsidyCents / 100, 2, '.', '');
?>
<form class="snk-form snk-form--settings" data-snk-form="settings" id="snk-benefits-form">
	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Monthly subsidy')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Euro amount covered by the company each period before payroll deduction.')); ?></p>
		<label class="snk-field" for="snk-subsidy-euro">
			<span><?php p($l->t('Monthly subsidy (€)')); ?></span>
			<input id="snk-subsidy-euro" name="subsidyAllowanceEuro" class="snk-input" type="number" min="0" step="0.01" inputmode="decimal" value="<?php p($subsidyEuro); ?>" />
		</label>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Hospitality')); ?></legend>
		<input type="hidden" name="hospitalityEnabled" value="0" />
		<div class="snk-switch-field">
			<input class="snk-switch-field__input" type="checkbox" role="switch" name="hospitalityEnabled" id="snk-hosp-enabled" value="1" <?php if (!empty($s['hospitalityEnabled'])) { ?>checked<?php } ?> />
			<label class="snk-switch-field__label" for="snk-hosp-enabled">
				<span class="snk-switch-field__track" aria-hidden="true"></span>
				<span class="snk-switch-field__text"><?php p($l->t('Hospitality')); ?>
					<span class="snk-switch-field__hint"><?php p($l->t('Company treats and who may use them.')); ?></span>
				</span>
			</label>
		</div>
		<div id="snk-hosp-fields" class="snk-settings-block__nested" <?php if (empty($s['hospitalityEnabled'])) { ?>hidden<?php } ?>>
			<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Search in each box below — picks go into that list only.')); ?></p>
			<div class="snk-access-roster snk-access-roster--stack">
				<div class="snk-access-roster__item">
					<h3 class="snk-access-roster__title" id="snk-hosp-company-label"><?php p($l->t('Company user')); ?></h3>
					<?php
					$name = 'hospitalityCompanyUserId';
					$value = (string)($s['hospitalityCompanyUserId'] ?? '');
					$picker = 'users';
					$single = true;
					$required = false;
					$inlineSearch = true;
					$listLabel = $l->t('Company user');
					$chips = $_['hospCompanyChips'] ?? [];
					$fieldId = 'snk-hosp-company';
					include __DIR__ . '/../snk-chip-field.php';
					?>
				</div>
				<div class="snk-access-roster__item">
					<h3 class="snk-access-roster__title" id="snk-hosp-allow-label"><?php p($l->t('Allowlist')); ?></h3>
					<?php
					$name = 'hospitalityAllowedUserIds';
					$value = implode(',', $_['hospAllowlist'] ?? []);
					$picker = 'users';
					$single = false;
					$required = false;
					$inlineSearch = true;
					$listLabel = $l->t('Hospitality allowlist');
					$chips = $_['hospAllowChips'] ?? [];
					$fieldId = 'snk-hosp-allow';
					include __DIR__ . '/../snk-chip-field.php';
					?>
				</div>
			</div>
			<p id="snk-hosp-save-hint" class="snk-callout snk-callout--warn" role="status" <?php
				$hospOn = !empty($s['hospitalityEnabled']);
				$hospOk = trim((string)($s['hospitalityCompanyUserId'] ?? '')) !== '' && !empty($_['hospAllowlist']);
				if (!$hospOn || $hospOk) {
					p(' hidden');
				}
			?>><?php p($l->t('Add a company user and allowlist, or Save will leave company treats off.')); ?></p>
		</div>
	</fieldset>

	<fieldset class="snk-settings-block">
		<legend class="snk-settings-block__legend"><?php p($l->t('Multi-site')); ?></legend>
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
	</fieldset>

	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary" id="snk-benefits-save"><?php p($l->t('Save')); ?></button>
	</div>
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
