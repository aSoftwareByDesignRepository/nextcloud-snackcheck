<?php
/**
 * Colleague proxy pick — inline search on the selected colleague field (Bachus).
 *
 * @var \OCP\IL10N $l
 * @var int|string $siteId
 * @var string|null $fieldId
 * @var string|null $resultsId
 * @var string|null $reasonHintId
 * @var bool|null $showLead
 * @var bool|null $embedded
 * @var bool|null $proxyPanelHidden
 */
$siteId = (int)($siteId ?? 0);
$fieldId = (string)($fieldId ?? 'snk-proxy-target');
$resultsId = (string)($resultsId ?? 'snk-proxy-user-results');
$reasonHintId = (string)($reasonHintId ?? 'snk-proxy-reason-hint');
$showLead = !isset($showLead) || !empty($showLead);
$embedded = !empty($embedded);
$titleId = $fieldId . '-panel-title';
$hiddenAttr = !empty($proxyPanelHidden) ? ' hidden' : '';
?>
<?php if ($embedded): ?>
<div class="snk-mode-panel snk-mode-panel--embedded" id="snk-mode-proxy"<?php echo $hiddenAttr; ?>>
	<?php if ($showLead): ?>
		<p class="snk-filter-panel__intro snk-mode-panel__lead" role="status"><?php p($l->t('Pick a colleague and reason, then tap a snack below.')); ?></p>
	<?php endif; ?>
<?php else: ?>
<section class="snk-card snk-filter-panel snk-mode-panel" id="snk-mode-proxy" aria-labelledby="<?php p($titleId); ?>"<?php echo $hiddenAttr; ?>>
	<header class="snk-filter-panel__head">
		<h2 id="<?php p($titleId); ?>"><?php p($l->t('Colleague')); ?></h2>
		<?php if ($showLead): ?>
			<p class="snk-filter-panel__intro snk-mode-panel__lead" role="status"><?php p($l->t('Pick a colleague and reason, then tap a snack below.')); ?></p>
		<?php endif; ?>
	</header>
	<div class="snk-filter-panel__body">
<?php endif; ?>
		<div class="snk-proxy-pick" data-snk-proxy-fields>
			<input type="hidden" name="siteId" value="<?php p($siteId); ?>" />
			<div class="snk-proxy-pick__step snk-proxy-pick__who" role="group" aria-labelledby="<?php p($fieldId); ?>-label">
				<p class="snk-proxy-pick__step-label" id="<?php p($fieldId); ?>-label">
					<span class="snk-proxy-pick__step-n" aria-hidden="true">1</span>
					<?php p($l->t('Colleague')); ?>
				</p>
				<?php
				$name = 'targetUserId';
				$value = '';
				$picker = 'users';
				$single = true;
				$required = true;
				$listLabel = $l->t('Selected colleague');
				$chips = [];
				$inlineSearch = true;
				$autoReady = true;
				$searchScope = 'access';
				include __DIR__ . '/snk-chip-field.php';
				$inlineSearch = false;
				$autoReady = false;
				$searchScope = 'directory';
				?>
			</div>
			<div class="snk-proxy-pick__step snk-proxy-pick__why">
				<p class="snk-proxy-pick__step-label" id="<?php p($reasonHintId); ?>-label">
					<span class="snk-proxy-pick__step-n" aria-hidden="true">2</span>
					<?php p($l->t('Why? (short note)')); ?>
				</p>
				<label class="snk-field">
					<span class="snk-sr-only"><?php p($l->t('Why? (short note)')); ?></span>
					<input class="snk-input" name="proxyReason" id="snk-proxy-reason" required minlength="3" maxlength="500" autocomplete="off" aria-labelledby="<?php p($reasonHintId); ?>-label" aria-describedby="<?php p($reasonHintId); ?>" />
					<span id="<?php p($reasonHintId); ?>" class="snk-muted"><?php p($l->t('At least 3 characters — e.g. Forgot badge')); ?></span>
				</label>
			</div>
		</div>
<?php if ($embedded): ?>
</div>
<?php else: ?>
	</div>
</section>
<?php endif; ?>
