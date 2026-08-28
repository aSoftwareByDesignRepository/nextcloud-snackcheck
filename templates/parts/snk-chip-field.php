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
 * @var bool|null $autoReady Single-target flows (proxy): no Choose… ritual — search is always live
 * @var bool|null $inlineSearch Embedded combobox per field (settings access roster)
 * @var string|null $searchScope API scope: directory|access (default directory)
 */
$name = (string)($name ?? '');
$value = (string)($value ?? '');
$picker = (string)($picker ?? 'users');
$single = !empty($single);
$required = !empty($required);
$autoReady = !empty($autoReady);
$inlineSearch = !empty($inlineSearch);
$searchScope = (string)($searchScope ?? 'directory');
if ($searchScope !== 'access') {
	$searchScope = 'directory';
}
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
$fieldClass = 'snk-chip-field';
if ($single) {
	$fieldClass .= ' snk-chip-field--single';
}
if ($autoReady) {
	$fieldClass .= ' snk-chip-field--auto is-active';
}
if ($inlineSearch) {
	$fieldClass .= ' snk-chip-field--inline is-active';
}
$searchId = $fieldId . '-search';
$resultsId = $fieldId . '-results';
$searchLabel = $picker === 'groups'
	? $l->t('Type a group name to add…')
	: $l->t('Type a name to add…');
$emptyInline = $picker === 'groups'
	? $l->t('No groups added yet — search above')
	: ($single
		? $l->t('Nobody selected yet — search above')
		: $l->t('Nobody added yet — search above'));
?>
<div class="<?php p($fieldClass); ?>" data-snk-chip-field<?php if ($autoReady || $inlineSearch) { ?> data-snk-chip-auto="1"<?php } ?>>
	<?php if ($inlineSearch): ?>
		<div class="snk-chip-field__search">
			<label class="snk-sr-only" for="<?php p($searchId); ?>"><?php p($listLabel); ?></label>
			<input
				id="<?php p($searchId); ?>"
				class="snk-input"
				type="search"
				data-snk-user-search
				data-snk-search-scope="<?php p($searchScope); ?>"
				autocomplete="off"
				aria-controls="<?php p($resultsId); ?>"
				placeholder="<?php p($searchLabel); ?>"
			/>
			<ul id="<?php p($resultsId); ?>" class="snk-user-results" data-snk-user-results role="listbox" aria-label="<?php p($l->t('Matching results')); ?>" aria-live="polite"></ul>
		</div>
	<?php endif; ?>
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
		<?php if ($autoReady || $inlineSearch) { ?>data-snk-active="1"<?php } ?>
	/>
	<?php if (!$autoReady && !$inlineSearch): ?>
		<button type="button" class="snk-btn snk-btn--secondary snk-chip-field__add" data-snk-chip-activate>
			<?php p($single ? $l->t('Choose…') : $l->t('Add…')); ?>
		</button>
		<p class="snk-muted snk-chip-field__empty" data-snk-chip-empty<?php if ($chips !== []) { ?> hidden<?php } ?>>
			<?php p($single
				? $l->t('No one selected yet — tap Choose… then search')
				: $l->t('No one selected yet — tap Add… then search')); ?>
		</p>
	<?php else: ?>
		<p class="snk-muted snk-chip-field__empty" data-snk-chip-empty<?php if ($chips !== []) { ?> hidden<?php } ?>>
			<?php p($inlineSearch ? $emptyInline : $l->t('Nobody yet — type a name above')); ?>
		</p>
	<?php endif; ?>
</div>
