<?php
/**
 * SnackCheck access-denied page (403/404 for gated routes).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

style('snackcheck', 'app');
$message = (string)($_['deniedMessage'] ?? $l->t('You do not have access to this page.'));
$backUrl = (string)($_['backUrl'] ?? '#');
$backLabel = (string)($_['backLabel'] ?? $l->t('Back to Log'));
?>
<div class="snk-denied">
	<h1><?php p($l->t('No access')); ?></h1>
	<p><?php p($message); ?></p>
	<p><a class="button primary" href="<?php p($backUrl); ?>"><?php p($backLabel); ?></a></p>
</div>
