<?php
/**
 * Settings · SNK2 license + kitchen tablets
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$s = $_['settings'] ?? [];
$lic = $_['license'] ?? null;
$siteNames = [];
foreach (($_['sites'] ?? []) as $siteRow) {
	$sid = (int)(is_object($siteRow) ? $siteRow->getId() : ($siteRow['id'] ?? 0));
	$siteNames[$sid] = (string)(is_object($siteRow) ? $siteRow->getName() : ($siteRow['name'] ?? ''));
}
$terminals = $_['terminals'] ?? [];
?>
<section class="snk-settings-block" aria-labelledby="snk-license-status-title">
	<h2 id="snk-license-status-title" class="snk-settings-block__legend"><?php p($l->t('License status')); ?></h2>
	<?php if ($lic): ?>
		<p class="snk-settings-status">
			<span><?php p($l->t('Customer')); ?>: <strong><?php p($lic['customerId']); ?></strong></span>
			<span aria-hidden="true">·</span>
			<span><?php p($l->t('Devices')); ?>: <strong><?php p(($_['terminalUsed'] ?? 0) . '/' . ($_['terminalLimit'] ?? 0)); ?></strong></span>
		</p>
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
		<p class="snk-muted"><?php p($l->t('No SNK2 license applied. Web stays free.')); ?></p>
	<?php endif; ?>
</section>

<form class="snk-form snk-form--settings snk-settings-block" data-snk-form="license">
	<fieldset class="snk-settings-block snk-settings-block--flush">
		<legend class="snk-settings-block__legend"><?php p($l->t('Apply SNK2 key')); ?></legend>
		<label class="snk-field" for="snk-license-key">
			<span><?php p($l->t('License key')); ?></span>
			<textarea id="snk-license-key" name="licenseKey" class="snk-textarea" rows="3" required></textarea>
		</label>
	</fieldset>
	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save license')); ?></button>
	</div>
</form>

<form class="snk-form snk-form--settings snk-settings-block" data-snk-form="terminal">
	<fieldset class="snk-settings-block snk-settings-block--flush">
		<legend class="snk-settings-block__legend"><?php p($l->t('Register kitchen tablet')); ?></legend>
		<label class="snk-field" for="snk-term-label">
			<span><?php p($l->t('Tablet label')); ?></span>
			<input id="snk-term-label" class="snk-input" name="label" required maxlength="128" placeholder="<?php p($l->t('e.g. Fridge')); ?>" />
		</label>
		<?php if (!empty($s['multiSiteEnabled']) || !empty($_['settings']['multiSiteEnabled'])): ?>
			<label class="snk-field" for="snk-term-site">
				<span><?php p($l->t('Kitchen site')); ?></span>
				<select id="snk-term-site" class="snk-select" name="siteId" required>
					<?php foreach (($_['sites'] ?? []) as $site): ?>
						<option value="<?php p(is_object($site) ? $site->getId() : ($site['id'] ?? 0)); ?>">
							<?php p(is_object($site) ? $site->getName() : ($site['name'] ?? '')); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
	</fieldset>
	<div class="snk-form-actions">
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Register')); ?></button>
	</div>
</form>

<?php if ($terminals !== []): ?>
	<section class="snk-settings-block" aria-labelledby="snk-term-list-title">
		<h2 id="snk-term-list-title" class="snk-settings-block__legend"><?php p($l->t('Kitchen tablets')); ?></h2>
		<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Revoke a tablet if it is lost, stolen, or replaced.')); ?></p>
		<ul class="snk-term-list" role="list">
			<?php foreach ($terminals as $term): ?>
				<?php
				$tid = (int)($term['id'] ?? 0);
				$tLabel = (string)($term['label'] ?? '');
				$tSite = $siteNames[(int)($term['siteId'] ?? 0)] ?? '';
				$tSeen = (string)($term['lastSeenAt'] ?? '');
				?>
				<li class="snk-term-list__row">
					<div class="snk-term-list__meta">
						<span class="snk-term-list__label"><?php p($tLabel !== '' ? $tLabel : $l->t('Kitchen tablet')); ?></span>
						<?php if ($tSite !== ''): ?>
							<span class="snk-muted"><?php p($tSite); ?></span>
						<?php endif; ?>
						<?php if ($tSeen !== ''): ?>
							<span class="snk-muted"><?php p($l->t('Last seen')); ?>: <?php p($tSeen); ?></span>
						<?php else: ?>
							<span class="snk-muted"><?php p($l->t('Not seen yet')); ?></span>
						<?php endif; ?>
					</div>
						<button type="button"
							class="snk-btn snk-btn--danger"
							data-snk-action="revoke-terminal"
							data-device-id="<?php p($tid); ?>"
							aria-label="<?php p($l->t('Revoke tablet') . ': ' . $tLabel); ?>">
							<?php p($l->t('Revoke')); ?>
						</button>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
