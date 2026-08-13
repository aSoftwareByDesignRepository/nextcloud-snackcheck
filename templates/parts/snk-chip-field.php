<?php
/**
 * Removable directory chips + hidden committed ids (DESIGN-SYSTEM §3.13 / ACCESS §1).
 *
 * @var \OCP\IL10N $l
 * @var string $name Form field name
 * @var string $value Committed CSV or single uid/gid
 * @var string $picker 'users'|'groups'
 * @var bool $single
 * @var bool $required
 * @var string $listLabel Accessible name for the chip list
 * @var list<array{id:string,displayName:string}> $chips
 * @var string|null $fieldId
 */
$name = (string)($name ?? '');
$value = (string)($value ?? '');
$picker = (string)($picker ?? 'users');
$single = !empty($single);
$required = !empty($required);
$listLabel = (string)($listLabel ?? $l->t('Selected'));
$chips = is_array($chips ?? null) ? $chips : [];
$fieldId = (string)($fieldId ?? ('snk-chip-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name)));
$labelMap = [];
foreach ($chips as $chip) {
	$id = trim((string)($chip['id'] ?? ''));
	if ($id === '') {
		continue;
	}
	$dn = trim((string)($chip['displayName'] ?? ''));
	$labelMap[$id] = $dn !== '' ? $dn : $id;
}
$labelsJson = json_encode($labelMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($labelsJson === false) {
	$labelsJson = '{}';
}
?>
<div class="snk-chip-field<?php if ($single) { ?> snk-chip-field--single<?php } ?>" data-snk-chip-field>
	<ul class="snk-chip-list" data-snk-chip-list role="list" aria-label="<?php p($listLabel); ?>">
		<?php foreach ($chips as $chip):
			$id = trim((string)($chip['id'] ?? ''));
			if ($id === '') {
				continue;
			}
			$dn = trim((string)($chip['displayName'] ?? ''));
			if ($dn === '') {
				$dn = $id;
			}
			?>
			<li class="snk-chip" role="listitem" data-snk-chip-id="<?php p($id); ?>">
				<span class="snk-chip__text"><?php p($dn); ?></span>
				<?php if ($dn !== $id): ?>
					<span class="snk-chip__id"><?php p($id); ?></span>
				<?php endif; ?>
				<button type="button" class="snk-chip__remove" data-snk-chip-remove
					aria-label="<?php p($l->t('Remove %s', [$dn])); ?>">×</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<input type="hidden"
		name="<?php p($name); ?>"
		id="<?php p($fieldId); ?>"
		class="snk-chip-target<?php if ($single) { ?> snk-chip-single<?php } ?>"
		value="<?php p($value); ?>"
		data-snk-picker="<?php p($picker); ?>"
		data-snk-labels="<?php p($labelsJson); ?>"
		<?php if ($required) { ?>required<?php } ?>
	/>
	<button type="button" class="snk-btn snk-btn--secondary snk-chip-field__add" data-snk-chip-activate>
		<?php p($single ? $l->t('Choose…') : $l->t('Add…')); ?>
	</button>
	<p class="snk-muted snk-chip-field__empty" data-snk-chip-empty<?php if ($chips !== []) { ?> hidden<?php } ?>>
		<?php p($l->t('No one selected yet — search below')); ?>
	</p>
</div>
