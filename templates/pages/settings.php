<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ $section = $_['section'] ?? 'access'; $s = $_['settings'] ?? []; ?>
<section class="snk-section" aria-label="<?php p($l->t('Settings')); ?>">
	<nav class="snk-settings-nav" aria-label="<?php p($l->t('Settings sections')); ?>">
		<?php
		$secLabels = [
			'access' => $l->t('Access'),
			'benefits' => $l->t('Benefits'),
			'privacy' => $l->t('Privacy'),
			'pulse' => $l->t('Pulse'),
			'digests' => $l->t('Digests'),
			'unlock' => $l->t('Unlock PIN / QR'),
			'license' => $l->t('License'),
			'support' => $l->t('Support'),
		];
		foreach ($secLabels as $sec => $label): ?>
			<a class="snk-settings-nav__link<?php if ($section===$sec) { p(' is-active'); } ?>" href="<?php p($urlGenerator->linkToRoute('snackcheck.page.settings', ['section'=>$sec])); ?>"<?php if ($section===$sec): ?> aria-current="page"<?php endif; ?>><?php p($label); ?></a>
		<?php endforeach; ?>
	</nav>

	<article class="snk-card">
		<div class="snk-card__body">
	<?php if ($section === 'license'): ?>
		<h2 class="snk-h2"><?php p($l->t('License')); ?></h2>
		<?php $lic = $_['license'] ?? null; ?>
		<?php if ($lic): ?>
			<p><?php p($l->t('Customer')); ?>: <?php p($lic['customerId']); ?> · <?php p($l->t('Devices')); ?>: <?php p(($_['terminalUsed']??0).'/'.($_['terminalLimit']??0)); ?></p>
			<?php if (empty($lic['active'])): ?>
				<div class="snk-callout snk-callout--warn" role="status">
					<?php if (empty($lic['instanceValid'])): ?>
						<p><?php p($l->t('This license is bound to another Nextcloud instance. Re-apply your SNK2 key on this server.')); ?></p>
					<?php elseif (empty($lic['dateValid'])): ?>
						<p><?php p($l->t('This license has expired. Apply a new SNK2 key.')); ?></p>
					<?php else: ?>
						<p><?php p($l->t('This license is not active. Apply a valid SNK2 key.')); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php else: ?>
			<p class="snk-empty"><?php p($l->t('No SNK2 license applied. Web stays free.')); ?></p>
		<?php endif; ?>
		<form class="snk-form" data-snk-form="license">
			<label class="snk-field" for="snk-license-key"><span><?php p($l->t('License key')); ?></span>
				<textarea id="snk-license-key" name="licenseKey" class="snk-textarea" rows="3" required></textarea>
			</label>
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Apply license')); ?></button>
		</form>
		<form class="snk-form" data-snk-form="terminal">
			<label class="snk-field" for="snk-term-label"><span><?php p($l->t('Register kitchen tablet')); ?></span>
				<input id="snk-term-label" name="label" required maxlength="128" />
			</label>
			<?php if (!empty($s['multiSiteEnabled']) || !empty($_['settings']['multiSiteEnabled'])): ?>
			<label class="snk-field" for="snk-term-site"><span><?php p($l->t('Kitchen site')); ?></span>
				<select id="snk-term-site" name="siteId" required>
					<?php foreach (($_['sites'] ?? []) as $site): ?>
						<option value="<?php p(is_object($site) ? $site->getId() : ($site['id'] ?? 0)); ?>">
							<?php p(is_object($site) ? $site->getName() : ($site['name'] ?? '')); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php endif; ?>
			<button type="submit" class="snk-btn"><?php p($l->t('Register')); ?></button>
		</form>

	<?php elseif ($section === 'access'): ?>
		<form class="snk-form" data-snk-form="settings">
			<label class="snk-field"><span><?php p($l->t('Access mode')); ?></span>
				<select name="accessMode">
					<option value="open" <?php if (($s['accessMode'] ?? '') === 'open') p('selected'); ?>><?php p($l->t('Open (all users)')); ?></option>
					<option value="listed" <?php if (($s['accessMode'] ?? '') === 'listed') p('selected'); ?>><?php p($l->t('Restricted (listed users/groups)')); ?></option>
				</select>
			</label>
			<label class="snk-field"><span><?php p($l->t('Allowed users')); ?></span>
				<?php
				$name = 'accessUsers';
				$value = implode(',', $s['accessUsers'] ?? []);
				$picker = 'users';
				$single = false;
				$required = false;
				$listLabel = $l->t('Allowed users');
				$chips = $_['accessUserChips'] ?? [];
				$fieldId = 'snk-access-users';
				include __DIR__ . '/../parts/snk-chip-field.php';
				?>
			</label>
			<label class="snk-field"><span><?php p($l->t('Allowed groups')); ?></span>
				<?php
				$name = 'accessGroups';
				$value = implode(',', $s['accessGroups'] ?? []);
				$picker = 'groups';
				$single = false;
				$required = false;
				$listLabel = $l->t('Allowed groups');
				$chips = $_['accessGroupChips'] ?? [];
				$fieldId = 'snk-access-groups';
				include __DIR__ . '/../parts/snk-chip-field.php';
				?>
			</label>
			<label class="snk-field"><span><?php p($l->t('App admins')); ?></span>
				<?php
				$name = 'appAdmins';
				$value = implode(',', $s['appAdmins'] ?? []);
				$picker = 'users';
				$single = false;
				$required = false;
				$listLabel = $l->t('App admins');
				$chips = $_['appAdminChips'] ?? [];
				$fieldId = 'snk-app-admins';
				include __DIR__ . '/../parts/snk-chip-field.php';
				?>
			</label>
			<div class="snk-chip-search">
				<label class="snk-field"><span><?php p($l->t('Find users')); ?> — <span class="snk-muted" data-snk-chip-hint><?php p($l->t('Choose… then search')); ?></span></span>
					<input type="search" data-snk-user-search data-snk-search-scope="directory" autocomplete="off" aria-controls="snk-access-user-results" />
				</label>
				<ul id="snk-access-user-results" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching people')); ?>" aria-live="polite"></ul>
			</div>
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>

	<?php elseif ($section === 'benefits'): ?>
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
					include __DIR__ . '/../parts/snk-chip-field.php';
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
					include __DIR__ . '/../parts/snk-chip-field.php';
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

	<?php elseif ($section === 'privacy'): ?>
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

	<?php elseif ($section === 'pulse'): ?>
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

	<?php elseif ($section === 'digests'): ?>
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

	<?php elseif ($section === 'unlock'): ?>
		<h2 class="snk-h2"><?php p($l->t('Unlock PIN / QR')); ?></h2>
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
				include __DIR__ . '/../parts/snk-chip-field.php';
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
				include __DIR__ . '/../parts/snk-chip-field.php';
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

	<?php else: ?>
		<p><?php p($l->t('SnackCheck honor ledger. Kitchen tablets use SNK2 device licences. Web stays free.')); ?></p>
		<p><a href="https://nextcloud.software-by-design.de/" rel="noopener"><?php p($l->t('Documentation')); ?></a></p>
		<p>
			<a class="snk-btn" href="<?php p($urlGenerator->linkTo('snackcheck', 'docs/DEVICE-SHORTLIST.md')); ?>" rel="noopener" target="_blank">
				<?php p($l->t('Kitchen tablet device shortlist')); ?>
			</a>
		</p>
		<details class="snk-details">
			<summary><?php p($l->t('Kitchen tablet shortlist (summary)')); ?></summary>
			<ul>
				<li><?php p($l->t('10″ Android tablet (Wi‑Fi), Android 10+ — prefer wall-mount kits')); ?></li>
				<li><?php p($l->t('Industrial wall tablet 8–10″ with kiosk firmware when available')); ?></li>
				<li><?php p($l->t('Refurbished business tablet + VESA mount for cost-effective LOI path')); ?></li>
			</ul>
			<p class="snk-muted"><?php p($l->t('Recommend-only — we do not RMA hardware. PIN/QR remain primary unlock.')); ?></p>
		</details>
	<?php endif; ?>
		</div>
	</article>
</section>
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
