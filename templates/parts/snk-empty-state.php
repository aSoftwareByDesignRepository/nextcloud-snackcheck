<?php
/**
 * Shared empty state — icon well + title + lead + optional CTA (AZC/PC parity).
 *
 * @var \OCP\IL10N $l
 * @var string $icon Lucide-style IconCatalog key
 * @var string $title
 * @var string $text
 * @var string|null $variant '' or 'error'
 */

use OCA\SnackCheck\Service\IconCatalog;

$icon = (string)($icon ?? 'inbox');
$title = (string)($title ?? '');
$text = (string)($text ?? '');
$variant = (string)($variant ?? '');
$class = 'snk-empty' . ($variant === 'error' ? ' snk-empty--error' : '');
?>
<div class="<?php p($class); ?>" role="status">
	<div class="snk-empty__icon" aria-hidden="true">
		<?php print_unescaped(IconCatalog::render($icon, 'snk-empty__icon-svg')); ?>
	</div>
	<?php if ($title !== ''): ?>
		<h3 class="snk-empty__title"><?php p($title); ?></h3>
	<?php endif; ?>
	<?php if ($text !== ''): ?>
		<p class="snk-empty__text"><?php p($text); ?></p>
	<?php endif; ?>
	<?php if (!empty($actionsHtml)): ?>
		<div class="snk-empty__actions"><?php print_unescaped($actionsHtml); ?></div>
	<?php endif; ?>
</div>
