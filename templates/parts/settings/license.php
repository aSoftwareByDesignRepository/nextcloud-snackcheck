<?php

declare(strict_types=1);

/**
 * SnackCheck Settings → License (SNK2 kitchen tablets).
 *
 * The license UX is the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * PageController feeds every canonical param — this template only
 * renders it. Do not put markup here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

// Keep the legacy deep-link anchor (#snk-license-key) working.
echo '<div id="snk-license-key">';
include __DIR__ . '/../../common/license-page.php';
echo '</div>';
