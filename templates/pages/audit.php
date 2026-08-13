<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<section class="snk-section" aria-label="<?php p($l->t('Audit')); ?>">
	<?php if (empty($_['events'])): ?>
		<?php
		$icon = 'clipboard-list';
		$title = $l->t('No audit events yet.');
		$text = $l->t('Changes to catalog, periods, and settings appear here.');
		$actionsHtml = '';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<article class="snk-card snk-card--table-solo">
		<div class="snk-table-wrap">
			<table class="snk-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('When')); ?></th>
					<th scope="col"><?php p($l->t('Actor')); ?></th>
					<th scope="col"><?php p($l->t('Action')); ?></th>
					<th scope="col"><?php p($l->t('Entity')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($_['events'] as $e): ?>
				<tr>
					<td><?php p($e['createdAt'] ?? ''); ?></td>
					<td><?php p($e['actor'] ?? ''); ?></td>
					<td><?php p($e['action'] ?? ''); ?></td>
					<td><?php p(($e['entityType'] ?? '') . ' ' . ($e['entityId'] ?? '')); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		</article>
	<?php endif; ?>
</section>
