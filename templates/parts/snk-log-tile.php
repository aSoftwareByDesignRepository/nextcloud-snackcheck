<?php
/**
 * Shared log tile — picture or category icon + name + price.
 *
 * @var array $item
 * @var int $siteId
 * @var bool $periodClosed
 * @var bool $shelfFocus
 * @var array<string, string> $tagLabels
 * @var \OCP\IL10N $l
 */
use OCA\SnackCheck\Service\IconCatalog;

$priceLabel = !empty($item['free'])
	? $l->t('Free')
	: number_format($item['priceCents'] / 100, 2, ',', '.') . ' €';
$aria = $item['name'] . ' — ' . $priceLabel;
$tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
$hazardOrder = ['contains_nuts', 'contains_alcohol'];
$tileTags = [];
foreach ($hazardOrder as $hazard) {
	if (in_array($hazard, $tags, true)) {
		$tileTags[] = $hazard;
	}
}
foreach ($tags as $tag) {
	if (in_array($tag, $tileTags, true)) {
		continue;
	}
	$tileTags[] = $tag;
	if (count($tileTags) >= 3) {
		break;
	}
}
$ariaParts = [$aria];
foreach ($tileTags as $tag) {
	$ariaParts[] = $tagLabels[$tag] ?? $tag;
}
$aria = implode(' · ', $ariaParts);
$iconKey = (string)($item['icon'] ?? IconCatalog::forCategory($item['category'] ?? null));
$cat = (string)($item['category'] ?? 'other');
$nameLower = mb_strtolower((string)$item['name']);
?>
<li data-snk-tile-item
	data-snk-cat="<?php p($cat); ?>"
	data-snk-name="<?php p($nameLower); ?>">
	<button type="button"
		class="snk-tile<?php if (!empty($shelfFocus)) { p(' snk-tile--focus'); } ?>"
		data-snk-action="log"
		data-item-id="<?php p($item['id']); ?>"
		data-site-id="<?php p($siteId); ?>"
		aria-label="<?php p($aria); ?>"
		<?php if (!empty($periodClosed)) { ?>disabled aria-disabled="true"<?php } ?>
		<?php if (!empty($shelfFocus)) { ?>autofocus<?php } ?>>
		<span class="snk-tile__media" aria-hidden="true">
			<?php if (!empty($item['hasImage']) && !empty($item['imageUrl'])): ?>
				<img class="snk-tile__img" src="<?php p($item['imageUrl']); ?>" alt="" loading="lazy" decoding="async" width="72" height="72" />
			<?php else: ?>
				<span class="snk-tile__icon">
					<?php print_unescaped(IconCatalog::render($iconKey, 'snk-tile__icon-svg')); ?>
				</span>
			<?php endif; ?>
		</span>
		<span class="snk-tile__body">
			<span class="snk-tile__name"><?php p($item['name']); ?></span>
			<span class="snk-tile__tags"<?php if ($tileTags === []) { ?> aria-hidden="true"<?php } ?>>
				<?php foreach ($tileTags as $tag): ?>
					<span class="snk-tag"><?php p($tagLabels[$tag] ?? $tag); ?></span>
				<?php endforeach; ?>
			</span>
			<span class="snk-tile__price">
				<?php if (!empty($item['free'])): ?>
					<?php p($l->t('Free')); ?>
				<?php else: ?>
					<?php p(number_format($item['priceCents'] / 100, 2, ',', '.') . ' €'); ?>
				<?php endif; ?>
			</span>
		</span>
	</button>
</li>
