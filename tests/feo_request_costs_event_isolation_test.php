<?php

$root = dirname(__DIR__);
$jsPath = $root . '/public/assets/js/feo-request-costs.js';
$js = is_file($jsPath) ? file_get_contents($jsPath) : false;
if (!is_string($js)) {
    fwrite(STDERR, "FAIL: cannot read public/assets/js/feo-request-costs.js\n");
    exit(1);
}

$must = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$must(
    str_contains($js, "qa('[data-cost-block-id]').forEach(row=>{row.addEventListener('click'"),
    'cost rows must contain single-click propagation locally'
);
$must(
    str_contains($js, "row.addEventListener('click',e=>{if(e.target.closest('a,input,textarea,select,button,label'))return;e.stopPropagation();})"),
    'single click must stop bubbling without opening another modal'
);
$must(
    str_contains($js, "qa('[data-cost-block-id]').forEach(row=>row.addEventListener('dblclick'"),
    'double click must remain the edit trigger'
);
$must(
    str_contains($js, "if(e.target.closest('a,input,textarea,select,button,label'))return;e.stopPropagation();openBlock(row.dataset.costBlockId)"),
    'double click must stop bubbling before opening the cost block'
);
$must(
    !str_contains($js, "addEventListener('click',e=>{if(e.target.closest('a,input,textarea,select,button,label'))return;openBlock("),
    'single click must never open a cost block'
);

echo "FEO_REQUEST_COST_EVENT_ISOLATION=PASS\n";
