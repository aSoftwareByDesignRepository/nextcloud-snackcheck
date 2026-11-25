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
		<legend class="snk-settings-block__legend"><?php p($l->t('Expenses')); ?></legend>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Members record out-of-pocket expenses that lower their payroll deduction.')); ?></p>
		<input type="hidden" name="expenseEnabled" value="0" />
		<div class="snk-switch-field">
			<input class="snk-switch-field__input" type="checkbox" role="switch" name="expenseEnabled" id="snk-expense-enabled" value="1" <?php if (!empty($s['expenseEnabled'])) { ?>checked<?php } ?> />
			<label class="snk-switch-field__label" for="snk-expense-enabled">
				<span class="snk-switch-field__track" aria-hidden="true"></span>
				<span class="snk-switch-field__text"><?php p($l->t('Expense credits')); ?>
					<span class="snk-switch-field__hint"><?php p($l->t('Expenses count against the payroll deduction of the same period.')); ?></span>
				</span>
			</label>
		</div>
		<div id="snk-expense-fields" class="snk-settings-block__nested" <?php if (empty($s['expenseEnabled'])) { ?>hidden<?php } ?>>
			<input type="hidden" name="expenseSelfEntry" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="expenseSelfEntry" id="snk-expense-self" value="1" <?php if (!isset($s['expenseSelfEntry']) || !empty($s['expenseSelfEntry'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-expense-self">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Members enter their own expenses')); ?>
						<span class="snk-switch-field__hint"><?php p($l->t('When off, only kitchen admins can record expenses.')); ?></span>
					</span>
				</label>
			</div>
			<label class="snk-field" for="snk-expense-max">
				<span><?php p($l->t('Self-entry limit per expense (€)')); ?></span>
				<input id="snk-expense-max" name="expenseSelfEntryMaxEuro" class="snk-input" type="number" min="0" step="0.01" inputmode="decimal" value="<?php p(number_format((int)($s['expenseSelfEntryMaxCents'] ?? 0) / 100, 2, '.', '')); ?>" />
				<span class="snk-muted"><?php p($l->t('0 = no limit. Kitchen admins may always enter any amount for anyone.')); ?></span>
			</label>
			<input type="hidden" name="expenseClampNegativeDeduct" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="expenseClampNegativeDeduct" id="snk-expense-clamp" value="1" <?php if (!empty($s['expenseClampNegativeDeduct'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-expense-clamp">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Cap at zero instead of paying out')); ?>
						<span class="snk-switch-field__hint"><?php p($l->t('When on, expenses can never push the deduction below 0 € — the remainder is reported as unapplied credit. When off, a negative deduction is paid out.')); ?></span>
					</span>
				</label>
			</div>
			<input type="hidden" name="expenseKioskEntry" value="0" />
			<div class="snk-switch-field">
				<input class="snk-switch-field__input" type="checkbox" role="switch" name="expenseKioskEntry" id="snk-expense-kiosk" value="1" <?php if (!empty($s['expenseKioskEntry'])) { ?>checked<?php } ?> />
				<label class="snk-switch-field__label" for="snk-expense-kiosk">
					<span class="snk-switch-field__track" aria-hidden="true"></span>
					<span class="snk-switch-field__text"><?php p($l->t('Expenses on the kitchen tablet')); ?>
						<span class="snk-switch-field__hint"><?php p($l->t('Lets people record expenses on the paired kitchen tablet (live connection required).')); ?></span>
					</span>
				</label>
			</div>
			<?php if (!empty($_['expenseClaimsCapability'])): ?>
				<fieldset class="snk-settings-block">
					<legend class="snk-settings-block__legend"><?php p($l->t('Where expenses go')); ?></legend>
					<?php $storedRouting = (string)($s['expenseRoutingStored'] ?? $s['expenseRouting'] ?? 'ledger_credit'); ?>
					<div class="snk-choice-cards">
						<label class="snk-choice-card">
							<input type="radio" name="expenseRouting" value="ledger_credit" <?php if ($storedRouting !== 'expense_claim') { ?>checked<?php } ?> />
							<span class="snk-choice-card__copy">
								<span class="snk-choice-card__title"><?php p($l->t('Credit directly (default)')); ?></span>
								<span class="snk-choice-card__hint"><?php p($l->t('Approved instantly, nets against this period.')); ?></span>
							</span>
						</label>
						<label class="snk-choice-card">
							<input type="radio" name="expenseRouting" value="expense_claim" <?php if ($storedRouting === 'expense_claim') { ?>checked<?php } ?> />
							<span class="snk-choice-card__copy">
								<span class="snk-choice-card__title"><?php p($l->t('Submit as ExpenseCheck claim')); ?></span>
								<span class="snk-choice-card__hint"><?php p($l->t('Goes through ExpenseCheck review; the credit lands after approval.')); ?></span>
							</span>
						</label>
					</div>
					<div class="snk-expense-claim-mapping">
						<label class="snk-field" for="snk-expense-claim-category">
							<span class="snk-field__label"><?php p($l->t('ExpenseCheck category ID')); ?></span>
							<input class="snk-input" type="number" min="0" step="1" inputmode="numeric" name="expenseClaimCategoryId" id="snk-expense-claim-category" value="<?php p((int)($s['expenseClaimCategoryId'] ?? 0)); ?>" />
						</label>
						<label class="snk-field" for="snk-expense-claim-costcenter">
							<span class="snk-field__label"><?php p($l->t('ExpenseCheck cost center ID')); ?></span>
							<input class="snk-input" type="number" min="0" step="1" inputmode="numeric" name="expenseClaimCostCenterId" id="snk-expense-claim-costcenter" value="<?php p((int)($s['expenseClaimCostCenterId'] ?? 0)); ?>" />
						</label>
						<p class="snk-muted"><?php p($l->t('Category and cost center IDs come from ExpenseCheck settings (admin area).')); ?></p>
						<?php if ($storedRouting === 'expense_claim' && empty($_['expenseClaimMappingReady'])): ?>
							<p class="snk-callout snk-callout--warn" role="alert"><?php p($l->t('Claim routing is inactive until both IDs point at existing ExpenseCheck entries — expenses still credit directly until then.')); ?></p>
						<?php endif; ?>
					</div>
				</fieldset>
			<?php endif; ?>
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

