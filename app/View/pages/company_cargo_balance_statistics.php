<?php if ($dbError !== null): ?>
<section class="manager-stats"><div class="cargo-report__error" role="alert"><?= e($dbError) ?></div></section>
<?php elseif ($statistics !== null): ?>
<section class="manager-stats cargo-report" data-cargo-balance></section>
<script type="application/json" id="cargo-balance-data"><?= json_encode($statistics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php endif; ?>
