<?php
/**
 * Approved registry page header. Icon and actions are trusted markup from the owning view;
 * title and summary are plain text. Shared geometry lives in registry-components.css.
 *
 * @var string $registryHeadTitle
 * @var string $registryHeadSummary
 * @var string $registryHeadIconHtml
 * @var string $registryHeadActionsHtml
 */
$registryHeadClasses = trim('page-head registry-ui-head ' . ($registryHeadExtraClass ?? ''));
?>
<div class="<?= e($registryHeadClasses) ?>">
    <div class="page-head-left">
        <span class="rp-identity-icon" aria-hidden="true"><?= $registryHeadIconHtml ?></span>
        <span class="rp-head-text"><h1 class="page-title"><?= e($registryHeadTitle) ?></h1><span class="page-summary"><?= e($registryHeadSummary) ?></span></span>
    </div>
    <?php if ($registryHeadActionsHtml !== ''): ?><div class="page-head-actions"><?= $registryHeadActionsHtml ?></div><?php endif; ?>
</div>
