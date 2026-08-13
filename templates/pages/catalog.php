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
			?>
				<tr<?php if ((int)$item->getActive() !== 1) { ?> class="snk-muted"<?php } ?>>
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
					<td title="<?php p($l->t('On hand / Target')); ?>"><?php p($stockLabel); ?></td>
					<td>
						<div class="snk-actions">
							<button type="button"
								class="snk-btn snk-btn--secondary"
								data-snk-action="edit-item"
								data-item-id="<?php p($item->getId()); ?>"
								data-name="<?php p($item->getName()); ?>"
								data-price-euro="<?php p($priceEuro); ?>"
								data-category="<?php p($item->getCategory() ?: 'other'); ?>"
								data-par="<?php p($item->getParLevel() ?? ''); ?>"
								data-on-hand="<?php p($item->getOnHand() ?? ''); ?>"
								data-tags="<?php p(implode(',', $tagList)); ?>"
								data-active="<?php p((int)$item->getActive()); ?>"><?php p($l->t('Edit')); ?></button>
						</div>
						<details class="snk-row-more">
							<summary><?php p($l->t('More')); ?></summary>
							<div class="snk-actions">
								<button type="button"
									class="snk-btn snk-btn--primary"
									data-snk-action="restock"
									data-item-id="<?php p($item->getId()); ?>"
									data-default-qty="1"
									data-instant="1"><?php p($l->t('Restock +1')); ?></button>
								<button type="button"
									class="snk-btn"
									data-snk-action="restock"
									data-item-id="<?php p($item->getId()); ?>"
									data-default-qty="1"><?php p($l->t('Restock other amount…')); ?></button>
								<?php if ($multiSite && $otherSites !== []): ?>
									<button type="button"
										class="snk-btn"
										data-snk-action="copy-item"
										data-item-id="<?php p($item->getId()); ?>"
										data-name="<?php p($item->getName()); ?>"><?php p($l->t('Copy to site')); ?></button>
								<?php endif; ?>
								<?php if ((int)$item->getActive() === 1): ?>
									<button type="button" class="snk-btn snk-btn--danger" data-snk-action="delete-item" data-item-id="<?php p($item->getId()); ?>"><?php p($l->t('Deactivate')); ?></button>
								<?php endif; ?>
								<a class="snk-btn snk-btn--secondary" href="<?php p($urlGenerator->linkToRouteAbsolute('snackcheck.page.shelf', ['itemId' => $item->getId()])); ?>"><?php p($l->t('Shelf link')); ?></a>
								<a class="snk-btn snk-btn--secondary" href="<?php p($urlGenerator->linkToRoute('snackcheck.api.shelfQr', ['id' => $item->getId()])); ?>"><?php p($l->t('Download QR')); ?></a>
							</div>
						</details>
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
				<span><?php p($l->t('Category')); ?></span>
				<select name="category">
					<option value="drink"><?php p($l->t('Drink')); ?></option>
					<option value="snack" selected><?php p($l->t('Snack')); ?></option>
					<option value="alcohol"><?php p($l->t('Alcohol')); ?></option>
					<option value="other"><?php p($l->t('Other')); ?></option>
				</select>
			</label>
			<label class="snk-field">
				<span><?php p($l->t('On hand')); ?></span>
				<input name="onHand" type="number" min="0" placeholder="<?php p($l->t('optional')); ?>" />
			</label>
			<label class="snk-field">
				<span><?php p($l->t('Par level')); ?></span>
				<input name="parLevel" type="number" min="0" placeholder="<?php p($l->t('optional')); ?>" />
			</label>
			<p class="snk-muted"><?php p($l->t('Leave stock blank unless you track fridge levels for Top-up.')); ?></p>
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

	<dialog id="snk-edit-item-dialog" class="snk-dialog" aria-labelledby="snk-edit-item-title">
		<form method="dialog" data-snk-form="catalog-update">
			<h2 id="snk-edit-item-title" class="snk-h2"><?php p($l->t('Edit item')); ?></h2>
			<input type="hidden" name="itemId" id="snk-edit-item-id" />
			<label class="snk-field"><span><?php p($l->t('Name')); ?></span>
				<input name="name" id="snk-edit-name" class="snk-input" required maxlength="120" />
			</label>
			<label class="snk-field"><span><?php p($l->t('Price (€)')); ?></span>
				<input name="priceEuro" id="snk-edit-price" class="snk-input" inputmode="decimal" required />
			</label>
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
				<label class="snk-field"><span><?php p($l->t('On hand')); ?></span>
					<input name="onHand" id="snk-edit-onhand" class="snk-input" type="number" min="0" />
				</label>
				<label class="snk-field"><span><?php p($l->t('Par level')); ?></span>
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
			<div class="snk-actions">
				<button type="submit" class="snk-btn snk-btn--primary" value="confirm"><?php p($l->t('Save')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
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
				<button type="submit" class="snk-btn snk-btn--danger" value="confirm"><?php p($l->t('Deactivate')); ?></button>
				<button type="submit" class="snk-btn snk-btn--secondary" value="cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>

</section>
