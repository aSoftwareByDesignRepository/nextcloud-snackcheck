<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ $multiSite = !empty($_['multiSite']); $otherSites = $_['otherSites'] ?? [];
$tagLabels = [
	'vegan' => $l->t('Vegan'),
	'vegetarian' => $l->t('Vegetarian'),
	'gluten_free' => $l->t('Gluten-free'),
	'lactose_free' => $l->t('Lactose-free'),
	'contains_nuts' => $l->t('Contains nuts'),
	'contains_alcohol' => $l->t('Contains alcohol'),
];
?>
<section class="snk-section" aria-label="<?php p($l->t('Catalog')); ?>">
	<?php if (!empty($_['empty'])): ?>
		<?php
		$icon = 'package';
		$title = $l->t('Catalog is empty.');
		$text = $l->t('One tap loads everyday drinks and snacks. Or add a single item below.');
		$actionsHtml = '<button type="button" class="snk-btn snk-btn--primary" data-snk-action="starter">'
			. htmlspecialchars($l->t('Load starter catalog'), ENT_QUOTES, 'UTF-8')
			. '</button>';
		include __DIR__ . '/../parts/snk-empty-state.php';
		?>
	<?php else: ?>
		<article class="snk-card snk-card--table-solo">
		<div class="snk-table-wrap">
		<table class="snk-table">
			<thead>
				<tr>
					<th scope="col" class="snk-table__thumb"><?php p($l->t('Picture')); ?></th>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Price')); ?></th>
					<th scope="col"><?php p($l->t('Category')); ?></th>
					<th scope="col"><?php p($l->t('Stock')); ?></th>
					<th scope="col"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($_['items'] as $item):
				$tagList = [];
				$rawTags = $item->getTagsJson();
				if (is_string($rawTags) && $rawTags !== '') {
					try {
						$decoded = json_decode($rawTags, true, 32, JSON_THROW_ON_ERROR);
						if (is_array($decoded)) {
							$tagList = array_values(array_filter($decoded, 'is_string'));
						}
					} catch (\Throwable $e) {
						$tagList = [];
					}
				}
				$priceEuro = number_format(((int)$item->getPriceCents()) / 100, 2, '.', '');
				$onHand = $item->getOnHand();
				$par = $item->getParLevel();
				$stockLabel = ($onHand === null && $par === null)
					? '—'
					: (($onHand ?? '—') . ' / ' . ($par ?? '—'));
				$hasImage = \OCA\SnackCheck\Service\CatalogImageService::hasImage($item);
				$thumbUrl = $hasImage
					? $urlGenerator->linkToRoute('snackcheck.api.catalogImage', ['id' => (int)$item->getId()])
						. '?v=' . rawurlencode((string)$item->getImageName())
					: '';
				$iconKey = \OCA\SnackCheck\Service\IconCatalog::forCategory($item->getCategory());
			?>
				<tr<?php if ((int)$item->getActive() !== 1) { ?> class="snk-muted"<?php } ?>>
					<td class="snk-table__thumb">
						<?php if ($hasImage): ?>
							<img class="snk-catalog-thumb" src="<?php p($thumbUrl); ?>" alt="" width="40" height="40" loading="lazy" />
						<?php else: ?>
							<span class="snk-catalog-thumb snk-catalog-thumb--icon" aria-hidden="true">
								<?php print_unescaped(\OCA\SnackCheck\Service\IconCatalog::render($iconKey)); ?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php p($item->getName()); ?>
						<?php if ((int)$item->getActive() !== 1): ?>
							<span class="snk-badge"><?php p($l->t('Inactive')); ?></span>
						<?php endif; ?>
					</td>
					<td><?php
						if (((int)$item->getPriceCents()) === 0) {
							p($l->t('Free'));
						} else {
							p(number_format($item->getPriceCents() / 100, 2, ',', '.') . ' €');
						}
					?></td>
					<td class="snk-muted"><?php p($item->getCategory() ?: '—'); ?></td>
					<td title="<?php p($l->t('In fridge / Target stock')); ?>"><?php p($stockLabel); ?></td>
					<td class="snk-table__actions">
						<?php
						$itemName = (string)$item->getName();
						$actionsLabel = $l->t('Actions for %s', [$itemName]);
						?>
						<div class="snk-row-actions" role="group" aria-label="<?php p($actionsLabel); ?>">
							<div class="snk-row-actions__main">
								<button type="button"
									class="snk-btn snk-btn--primary"
									data-snk-action="restock"
									data-item-id="<?php p($item->getId()); ?>"
									data-default-qty="1"
									data-instant="1"
									title="<?php p($l->t('Adds 1 in one tap.')); ?>"><?php p($l->t('Restock +1')); ?></button>
								<button type="button"
									class="snk-btn snk-btn--secondary"
									data-snk-action="edit-item"
									data-item-id="<?php p($item->getId()); ?>"
									data-name="<?php p($itemName); ?>"
									data-price-euro="<?php p($priceEuro); ?>"
									data-category="<?php p($item->getCategory() ?: 'other'); ?>"
									data-par="<?php p($item->getParLevel() ?? ''); ?>"
									data-on-hand="<?php p($item->getOnHand() ?? ''); ?>"
									data-tags="<?php p(implode(',', $tagList)); ?>"
									data-active="<?php p((int)$item->getActive()); ?>"
									data-has-image="<?php p($hasImage ? '1' : '0'); ?>"
									data-image-url="<?php p($thumbUrl); ?>"><?php p($l->t('Edit')); ?></button>
								<details class="snk-row-actions__more snk-row-more">
									<summary><?php p($l->t('More')); ?></summary>
									<div class="snk-row-actions__panel" role="group" aria-label="<?php p($l->t('More actions')); ?>">
										<button type="button"
											class="snk-row-actions__item"
											data-snk-action="restock"
											data-item-id="<?php p($item->getId()); ?>"
											data-default-qty="1"><?php p($l->t('Restock other amount…')); ?></button>
										<?php if ($multiSite && $otherSites !== []): ?>
											<button type="button"
												class="snk-row-actions__item"
												data-snk-action="copy-item"
												data-item-id="<?php p($item->getId()); ?>"
												data-name="<?php p($itemName); ?>"><?php p($l->t('Copy to site')); ?></button>
										<?php endif; ?>
										<a class="snk-row-actions__item"
											href="<?php p($urlGenerator->linkToRoute('snackcheck.page.shelf', ['itemId' => $item->getId()])); ?>"><?php p($l->t('Shelf link')); ?></a>
										<a class="snk-row-actions__item"
											href="<?php p($urlGenerator->linkToRoute('snackcheck.api.shelfQr', ['id' => $item->getId()])); ?>"><?php p($l->t('Download QR')); ?></a>
										<?php if ((int)$item->getActive() === 1): ?>
											<button type="button"
												class="snk-row-actions__item snk-row-actions__item--danger"
												data-snk-action="delete-item"
												data-item-id="<?php p($item->getId()); ?>"><?php p($l->t('Deactivate')); ?></button>
										<?php endif; ?>
									</div>
								</details>
							</div>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		</article>
	<?php endif; ?>

	<article class="snk-card" id="snk-catalog-add">
		<header class="snk-card__header">
			<div class="snk-card__header-text">
				<h2 class="snk-card__title"><?php p(!empty($_['empty']) ? $l->t('Add one item') : $l->t('Add item')); ?></h2>
				<p class="snk-card__lead"><?php p($l->t('Name and price first. Extra fields stay under More options.')); ?></p>
			</div>
		</header>
		<div class="snk-card__body">
	<form class="snk-form" data-snk-form="catalog-create" aria-label="<?php p($l->t('Add catalog item')); ?>">
		<label class="snk-field">
			<span><?php p($l->t('Name')); ?></span>
			<input name="name" required maxlength="120" autocomplete="off" />
		</label>
		<label class="snk-field">
			<span><?php p($l->t('Price (€)')); ?></span>
			<input name="priceEuro" inputmode="decimal" value="0,50" required />
		</label>
		<details class="snk-details">
			<summary><?php p($l->t('More options')); ?></summary>
			<p class="snk-muted"><?php p($l->t('Set price to 0 for complimentary (Free) items — logged for restock, excluded from payroll.')); ?></p>
			<label class="snk-field">
				<span><?php p($l->t('Picture (optional)')); ?></span>
				<input name="image" type="file" accept="image/jpeg,image/png,image/webp" />
			</label>
			<p class="snk-muted"><?php p($l->t('JPEG, PNG or WebP — max 2 MB. Helps people spot snacks faster.')); ?></p>
			<label class="snk-field">
				<span><?php p($l->t('Category')); ?></span>
				<select name="category">
					<option value="drink"><?php p($l->t('Drink')); ?></option>
					<option value="snack" selected><?php p($l->t('Snack')); ?></option>
					<option value="alcohol"><?php p($l->t('Alcohol')); ?></option>
					<option value="other"><?php p($l->t('Other')); ?></option>
				</select>
			</label>
			<label class="snk-field">
				<span><?php p($l->t('In fridge')); ?></span>
				<input name="onHand" type="number" min="0" placeholder="<?php p($l->t('optional')); ?>" />
			</label>
			<label class="snk-field">
				<span><?php p($l->t('Target stock')); ?></span>
				<input name="parLevel" type="number" min="0" placeholder="<?php p($l->t('optional')); ?>" />
			</label>
			<p class="snk-muted"><?php p($l->t('Leave stock blank unless you track fridge levels for restock.')); ?></p>
			<fieldset class="snk-fieldset">
				<legend><?php p($l->t('Diet / allergen tags')); ?></legend>
				<p class="snk-muted"><?php p($l->t('Shown as text on tiles (not colour-only).')); ?></p>
				<label class="snk-check"><input type="checkbox" name="tags[]" value="vegan" /> <?php p($l->t('Vegan')); ?></label>
				<label class="snk-check"><input type="checkbox" name="tags[]" value="vegetarian" /> <?php p($l->t('Vegetarian')); ?></label>
				<label class="snk-check"><input type="checkbox" name="tags[]" value="gluten_free" /> <?php p($l->t('Gluten-free')); ?></label>
				<label class="snk-check"><input type="checkbox" name="tags[]" value="lactose_free" /> <?php p($l->t('Lactose-free')); ?></label>
				<label class="snk-check"><input type="checkbox" name="tags[]" value="contains_nuts" /> <?php p($l->t('Contains nuts')); ?></label>
				<label class="snk-check"><input type="checkbox" name="tags[]" value="contains_alcohol" /> <?php p($l->t('Contains alcohol')); ?></label>
			</fieldset>
		</details>
		<input type="hidden" name="siteId" value="<?php p($_['siteId'] ?? 0); ?>" />
		<button type="submit" class="snk-btn snk-btn--primary"><?php p($l->t('Add')); ?></button>
	</form>
		</div>
	</article>

	<dialog id="snk-edit-item-dialog" class="snk-dialog snk-dialog--edit" aria-labelledby="snk-edit-item-title" aria-describedby="snk-edit-item-desc">
		<form method="dialog" data-snk-form="catalog-update" class="snk-dialog__form">
			<header class="snk-dialog__head">
				<h2 id="snk-edit-item-title" class="snk-h2"><?php p($l->t('Edit item')); ?></h2>
				<p id="snk-edit-item-desc" class="snk-dialog__lead snk-muted"><?php p($l->t('Change name, price, or picture. Extra fields stay under More options.')); ?></p>
			</header>
			<div class="snk-dialog__body">
				<input type="hidden" name="itemId" id="snk-edit-item-id" />
				<label class="snk-field"><span><?php p($l->t('Name')); ?></span>
					<input name="name" id="snk-edit-name" class="snk-input" required maxlength="120" data-snk-initial-focus autocomplete="off" />
				</label>
				<label class="snk-field"><span><?php p($l->t('Price (€)')); ?></span>
					<input name="priceEuro" id="snk-edit-price" class="snk-input" inputmode="decimal" required />
				</label>
				<div class="snk-edit-photo" data-snk-edit-photo>
					<span class="snk-edit-photo__label" id="snk-edit-photo-label"><?php p($l->t('Picture (optional)')); ?></span>
					<div class="snk-edit-photo__row">
						<div class="snk-edit-photo__frame" data-snk-edit-photo-preview data-has-preview="0" aria-labelledby="snk-edit-photo-label">
							<img alt="" width="88" height="88" data-snk-edit-photo-img hidden />
							<p class="snk-edit-photo__placeholder" data-snk-edit-photo-placeholder><?php p($l->t('No picture yet')); ?></p>
						</div>
						<div class="snk-edit-photo__controls">
							<label class="snk-btn snk-btn--secondary snk-edit-photo__pick">
								<span data-snk-edit-photo-pick-label><?php p($l->t('Choose picture')); ?></span>
								<input class="snk-sr-only" name="image" id="snk-edit-image" type="file" accept="image/jpeg,image/png,image/webp" data-snk-edit-photo-input />
							</label>
							<button type="button" class="snk-btn snk-btn--secondary" data-snk-action="clear-item-image" hidden><?php p($l->t('Remove picture')); ?></button>
							<p class="snk-muted snk-edit-photo__hint"><?php p($l->t('JPEG, PNG or WebP — max 2 MB.')); ?></p>
						</div>
					</div>
				</div>
				<details class="snk-details">
					<summary><?php p($l->t('More options')); ?></summary>
					<label class="snk-field"><span><?php p($l->t('Category')); ?></span>
						<select name="category" id="snk-edit-category" class="snk-select">
							<option value="drink"><?php p($l->t('Drink')); ?></option>
							<option value="snack"><?php p($l->t('Snack')); ?></option>
							<option value="alcohol"><?php p($l->t('Alcohol')); ?></option>
							<option value="other"><?php p($l->t('Other')); ?></option>
						</select>
					</label>
					<label class="snk-field"><span><?php p($l->t('In fridge')); ?></span>
						<input name="onHand" id="snk-edit-onhand" class="snk-input" type="number" min="0" />
					</label>
					<label class="snk-field"><span><?php p($l->t('Target stock')); ?></span>
						<input name="parLevel" id="snk-edit-par" class="snk-input" type="number" min="0" />
					</label>
					<fieldset class="snk-fieldset">
						<legend><?php p($l->t('Diet / allergen tags')); ?></legend>
						<?php foreach (['vegan'=>'Vegan','vegetarian'=>'Vegetarian','gluten_free'=>'Gluten-free','lactose_free'=>'Lactose-free','contains_nuts'=>'Contains nuts','contains_alcohol'=>'Contains alcohol'] as $val => $label): ?>
							<label class="snk-check"><input type="checkbox" name="tags[]" value="<?php p($val); ?>" class="snk-edit-tag" data-tag="<?php p($val); ?>" /> <?php p($l->t($label)); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<label class="snk-field"><span><?php p($l->t('Active')); ?></span>
						<select name="active" id="snk-edit-active" class="snk-select">
							<option value="1"><?php p($l->t('Yes')); ?></option>
							<option value="0"><?php p($l->t('No')); ?></option>
						</select>
					</label>
				</details>
			</div>
			<div class="snk-dialog__foot snk-actions">
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" class="snk-btn snk-btn--primary" value="confirm"><?php p($l->t('Save')); ?></button>
			</div>
		</form>
	</dialog>

	<?php if ($multiSite && $otherSites !== []): ?>
	<dialog id="snk-copy-item-dialog" class="snk-dialog" aria-labelledby="snk-copy-item-title">
		<form method="dialog" data-snk-form="catalog-copy">
			<h2 id="snk-copy-item-title" class="snk-h2"><?php p($l->t('Copy to site')); ?></h2>
			<p id="snk-copy-item-name" class="snk-muted"></p>
			<input type="hidden" name="itemId" id="snk-copy-item-id" />
			<label class="snk-field"><span><?php p($l->t('Target site')); ?></span>
				<select name="targetSiteId" id="snk-copy-target" required>
					<?php foreach ($otherSites as $site): ?>
						<option value="<?php p(is_object($site) ? $site->getId() : ($site['id'] ?? 0)); ?>">
							<?php p(is_object($site) ? $site->getName() : ($site['name'] ?? '')); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--primary" value="confirm"><?php p($l->t('Copy')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>
	<?php endif; ?>

	<dialog id="snk-restock-dialog" class="snk-dialog" aria-labelledby="snk-restock-title">
		<form method="dialog" data-snk-form="catalog-restock">
			<h2 id="snk-restock-title" class="snk-h2"><?php p($l->t('Restock')); ?></h2>
			<input type="hidden" name="itemId" id="snk-restock-item-id" />
			<label class="snk-field"><span><?php p($l->t('Add quantity')); ?></span>
				<input name="qty" id="snk-restock-qty" type="number" min="1" step="1" value="1" required />
			</label>
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--primary" value="confirm"><?php p($l->t('Restock')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>

	<dialog id="snk-deactivate-dialog" class="snk-dialog" aria-labelledby="snk-deactivate-title">
		<form method="dialog" data-snk-form="catalog-deactivate">
			<h2 id="snk-deactivate-title" class="snk-h2"><?php p($l->t('Deactivate')); ?></h2>
			<p><?php p($l->t('Hide this item from logging? You can reactivate it later via Edit.')); ?></p>
			<input type="hidden" name="itemId" id="snk-deactivate-item-id" />
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" class="snk-btn snk-btn--danger" value="confirm"><?php p($l->t('Deactivate')); ?></button>
			</div>
		</form>
	</dialog>

</section>
