<?php
/**
 * Settings · license
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 */
$s = $_['settings'] ?? [];
?>
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

		<?php
		$siteNames = [];
		foreach (($_['sites'] ?? []) as $siteRow) {
			$sid = (int)(is_object($siteRow) ? $siteRow->getId() : ($siteRow['id'] ?? 0));
			$siteNames[$sid] = (string)(is_object($siteRow) ? $siteRow->getName() : ($siteRow['name'] ?? ''));
		}
		$terminals = $_['terminals'] ?? [];
		?>
		<?php if ($terminals !== []): ?>
			<h3 class="snk-h3"><?php p($l->t('Kitchen tablets')); ?></h3>
			<p class="snk-muted"><?php p($l->t('Revoke a tablet if it is lost, stolen, or replaced.')); ?></p>
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
		<?php endif; ?>
