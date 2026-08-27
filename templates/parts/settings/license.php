<?php
/**
 * Settings · SNK2 license + kitchen tablets
 *
 * Web UI stays free (AGPL). SNK2 only unlocks kitchen tablet device seats.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
declare(strict_types=1);

$s = $_['settings'] ?? [];
$lic = is_array($_['license'] ?? null) ? $_['license'] : null;
$siteNames = [];
foreach (($_['sites'] ?? []) as $siteRow) {
	$sid = (int)(is_object($siteRow) ? $siteRow->getId() : ($siteRow['id'] ?? 0));
	$siteNames[$sid] = (string)(is_object($siteRow) ? $siteRow->getName() : ($siteRow['name'] ?? ''));
}
$terminals = is_array($_['terminals'] ?? null) ? $_['terminals'] : [];
$terminalUsed = (int)($_['terminalUsed'] ?? 0);
$terminalLimit = (int)($_['terminalLimit'] ?? 0);
$productsUrl = (string)($_['productsUrl'] ?? \OCA\SnackCheck\Support\SupportUsLinks::SITE_ORIGIN . '/');
$licenseRenewMailto = (string)($_['licenseRenewMailto'] ?? 'mailto:info@software-by-design.de?subject=' . rawurlencode('SnackCheck: kitchen tablet license'));
$instanceId = (string)($_['instanceId'] ?? '');
$newTabHint = $l->t('(opens in a new tab)');

$hasLicense = $lic !== null;
$dateValid = $hasLicense && !empty($lic['dateValid']);
$cryptoValid = $hasLicense && !empty($lic['cryptographicallyValid']);
$instanceValid = $hasLicense && !empty($lic['instanceValid']);
$isActive = $hasLicense && !empty($lic['active']);
$cryptoInvalid = $hasLicense && $dateValid && !$cryptoValid;
$validUntil = $hasLicense ? (string)($lic['validUntil'] ?? '') : '';
$customerId = $hasLicense ? (string)($lic['customerId'] ?? '') : '';

$expiresSoon = false;
$daysLeft = null;
if ($isActive && $validUntil !== '') {
	$untilDt = \DateTimeImmutable::createFromFormat('Y-m-d', $validUntil);
	$today = new \DateTimeImmutable('today');
	if ($untilDt instanceof \DateTimeImmutable) {
		$daysLeft = (int)$today->diff($untilDt)->format('%r%a');
		$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;
	}
}

$terminalPct = $terminalLimit > 0
	? min(100, (int)round(($terminalUsed / $terminalLimit) * 100))
	: 0;
$terminalFull = $terminalLimit > 0 && $terminalUsed >= $terminalLimit;
$devicesUsedText = str_replace(
	['{used}', '{total}'],
	[(string)$terminalUsed, (string)$terminalLimit],
	$l->t('{used} of {total} tablet seats')
);

if (!$hasLicense) {
	$badgeText = $l->t('Not configured');
	$badgeClass = 'snk-badge snk-badge--muted';
} elseif ($isActive && $expiresSoon) {
	$badgeText = $l->t('Active — renew soon');
	$badgeClass = 'snk-badge snk-badge--warn';
} elseif ($isActive) {
	$badgeText = $l->t('Active');
	$badgeClass = 'snk-badge snk-badge--ok';
} elseif ($cryptoInvalid) {
	$badgeText = $l->t('Signature mismatch');
	$badgeClass = 'snk-badge snk-badge--warn';
} else {
	$badgeText = $l->t('Expired or invalid');
	$badgeClass = 'snk-badge snk-badge--warn';
}

$ctaPrimaryLabel = $hasLicense
	? $l->t('Purchase or renew license')
	: $l->t('Ask for a license');
?>
<div class="snk-license-page" id="snk-license-page">

	<section class="snk-settings-block snk-license-intro" aria-labelledby="snk-license-intro-title">
		<h2 id="snk-license-intro-title" class="snk-settings-block__legend"><?php p($l->t('What this license does')); ?></h2>
		<p class="snk-license-intro__text">
			<?php p($l->t('The SnackCheck web app always stays free. An SNK2 license unlocks kitchen tablets for your organisation — shared devices staff use to log snacks.')); ?>
		</p>
		<div class="snk-license-cta" role="group" aria-label="<?php p($l->t('License options')); ?>">
			<a class="snk-btn snk-btn--primary snk-license-cta__link" href="<?php p($licenseRenewMailto); ?>">
				<?php p($ctaPrimaryLabel); ?>
			</a>
			<a
				class="snk-btn snk-btn--secondary snk-license-cta__link"
				href="<?php p($productsUrl); ?>"
				target="_blank"
				rel="noopener noreferrer"
				aria-label="<?php p($l->t('Software by Design — Nextcloud Apps') . ' ' . $newTabHint); ?>">
				<?php p($l->t('Software by Design — Nextcloud Apps')); ?>
			</a>
		</div>
		<?php if ($instanceId !== '' && $instanceId !== 'unknown-instance'): ?>
			<p class="snk-muted snk-license-instance">
				<span class="snk-field__label"><?php p($l->t('Nextcloud instance ID')); ?></span>
				<code class="snk-license-instance__id" translate="no"><?php p($instanceId); ?></code>
				<span class="snk-field__hint"><?php p($l->t('Include this ID when you request a license so we can bind the key to this server.')); ?></span>
			</p>
		<?php endif; ?>
	</section>

	<?php if (!$hasLicense): ?>
		<div class="snk-callout snk-callout--info" role="status" aria-labelledby="snk-license-no-key-title">
			<p id="snk-license-no-key-title"><strong><?php p($l->t('No license yet')); ?></strong></p>
			<p class="snk-callout__text"><?php p($l->t('Paste your SNK2 key below to unlock kitchen tablets. The web app stays free.')); ?></p>
		</div>
	<?php endif; ?>

	<?php if ($cryptoInvalid): ?>
		<div class="snk-callout snk-callout--warn" role="alert" aria-labelledby="snk-license-crypto-title">
			<p id="snk-license-crypto-title"><strong><?php p($l->t('License signature cannot be verified')); ?></strong></p>
			<p class="snk-callout__text"><?php p($l->t('A license key is stored, but this server cannot verify its signature. Re-apply a correctly signed SNK2 key, or contact support after an app update.')); ?></p>
		</div>
	<?php elseif ($hasLicense && !$isActive): ?>
		<div class="snk-callout snk-callout--warn" role="status">
			<?php if (!$instanceValid): ?>
				<p><?php p($l->t('This license is bound to another Nextcloud instance. Re-apply your SNK2 key on this server.')); ?></p>
			<?php elseif (!$dateValid): ?>
				<p><?php p($l->t('This license has expired. Apply a new SNK2 key.')); ?></p>
			<?php else: ?>
				<p><?php p($l->t('This license is not active. Apply a valid SNK2 key.')); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ($isActive && $expiresSoon && $daysLeft !== null): ?>
		<div class="snk-callout snk-callout--warn" role="status" aria-labelledby="snk-license-expiry-title">
			<p id="snk-license-expiry-title"><strong><?php p($l->t('Renew soon')); ?></strong></p>
			<p class="snk-callout__text"><?php p(str_replace('{days}', (string)max(0, $daysLeft), $l->t('This license expires in {days} days. Renew to keep kitchen tablets working.'))); ?></p>
			<div class="snk-license-cta snk-license-cta--inline">
				<a class="snk-btn snk-btn--secondary" href="<?php p($licenseRenewMailto); ?>"><?php p($l->t('Purchase or renew license')); ?></a>
			</div>
		</div>
	<?php endif; ?>

	<section class="snk-settings-block" aria-labelledby="snk-license-status-title">
		<h2 id="snk-license-status-title" class="snk-settings-block__legend"><?php p($l->t('License status')); ?></h2>
		<div class="snk-license-status">
			<div class="snk-license-status__row">
				<span class="<?php p($badgeClass); ?>"><?php p($badgeText); ?></span>
				<?php if ($validUntil !== ''): ?>
					<span class="snk-license-status__until">
						<?php p($l->t('Valid until')); ?>:
						<strong><?php p($validUntil); ?></strong>
					</span>
				<?php endif; ?>
			</div>
			<?php if ($customerId !== ''): ?>
				<p class="snk-settings-status">
					<span><?php p($l->t('Customer')); ?>: <strong><?php p($customerId); ?></strong></span>
				</p>
			<?php endif; ?>
			<div class="snk-license-meter-wrap">
				<p id="snk-license-meter-label" class="snk-license-meter-label"><?php p($l->t('Tablet seats')); ?></p>
				<div
					class="snk-license-meter<?php p($terminalFull ? ' snk-license-meter--full' : ''); ?>"
					role="meter"
					aria-labelledby="snk-license-meter-label"
					aria-valuemin="0"
					aria-valuenow="<?php p((string)$terminalUsed); ?>"
					aria-valuemax="<?php p((string)max($terminalLimit, $terminalUsed, 1)); ?>"
					aria-valuetext="<?php p($devicesUsedText); ?>">
					<div class="snk-license-meter__fill" style="width: <?php p((string)$terminalPct); ?>%"></div>
				</div>
				<p class="snk-license-meter-text"><?php p($devicesUsedText); ?></p>
			</div>
		</div>
	</section>

	<form class="snk-form snk-form--settings snk-settings-block" data-snk-form="license">
		<fieldset class="snk-settings-block snk-settings-block--flush">
			<legend class="snk-settings-block__legend"><?php p($l->t('Apply SNK2 key')); ?></legend>
			<label class="snk-field" for="snk-license-key">
				<span class="snk-field__label"><?php p($l->t('License key')); ?></span>
				<textarea
					id="snk-license-key"
					name="licenseKey"
					class="snk-textarea"
					rows="4"
					required
					autocomplete="off"
					autocapitalize="off"
					spellcheck="false"
					placeholder="<?php p($l->t('SNK2.…')); ?>"
					aria-describedby="snk-license-key-hint"></textarea>
				<span id="snk-license-key-hint" class="snk-field__hint"><?php p($l->t('Paste the full SNK2 key from your invoice. Line breaks are fine.')); ?></span>
			</label>
		</fieldset>
		<div class="snk-form-actions">
			<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Save license')); ?></button>
			<?php if ($hasLicense): ?>
				<button
					type="button"
					class="snk-btn snk-btn--danger"
					data-snk-action="clear-license"
					aria-describedby="snk-license-remove-hint">
					<?php p($l->t('Remove license')); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php if ($hasLicense): ?>
			<p id="snk-license-remove-hint" class="snk-muted snk-settings-block__hint">
				<?php p($l->t('Removing the license stops kitchen tablets immediately. The web app stays free.')); ?>
			</p>
		<?php endif; ?>
	</form>

	<form class="snk-form snk-form--settings snk-settings-block" data-snk-form="terminal">
		<fieldset class="snk-settings-block snk-settings-block--flush">
			<legend class="snk-settings-block__legend"><?php p($l->t('Register kitchen tablet')); ?></legend>
			<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Each registered tablet uses one seat from your SNK2 license.')); ?></p>
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
			<button type="submit" class="snk-btn snk-btn--primary"<?php if (!$isActive || $terminalLimit < 1) { p(' disabled'); } ?>>
				<?php p($l->t('Register')); ?>
			</button>
		</div>
		<?php if (!$isActive || $terminalLimit < 1): ?>
			<p class="snk-muted snk-settings-block__hint"><?php p($l->t('Apply an active SNK2 key with tablet seats before registering a device.')); ?></p>
		<?php endif; ?>
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
</div>
