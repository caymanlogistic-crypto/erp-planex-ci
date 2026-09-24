<?php if ($dbError !== null): ?>
<section class="fs-page"><div class="fs-caveat" role="alert"><?= e($dbError) ?></div></section>
<?php elseif ($statistics !== null): ?>
<div id="feo-share-root"></div>
<script type="application/json" id="feo-share-data"><?= json_encode($statistics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php endif; ?>
