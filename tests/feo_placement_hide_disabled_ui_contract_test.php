<?php

declare(strict_types=1);

$view = (string) file_get_contents(__DIR__ . '/../app/View/pages/company_feo_map_placement.php');

if (!str_contains($view, 'data-placement-hide-disabled checked')) {
    throw new RuntimeException('hide-disabled checkbox must be checked by default');
}
if (!str_contains($view, 'Не показывать отключённые')) {
    throw new RuntimeException('hide-disabled checkbox label is missing');
}
if (!str_contains($view, 'data-placement-block-status="<?= $statusApplied ? \'applied\' : \'disabled\' ?>"')) {
    throw new RuntimeException('placement rows must expose applied/disabled status');
}
if (!str_contains($view, '.feo-placement-table-card:has([data-placement-hide-disabled]:checked) .feo-placement-table tbody tr[data-placement-block-status="disabled"]{display:none}')) {
    throw new RuntimeException('checked hide-disabled control must hide disabled rows');
}
if (!str_contains($view, '.feo-placement-hide-disabled input[type="checkbox"]{width:15px;height:15px;margin:0;accent-color:var(--accent)}')) {
    throw new RuntimeException('hide-disabled checkbox must use the system brown accent');
}
if (!str_contains($view, 'data-placement-block-type="<?= e((string)($block[\'block_type\'] ?? \'\')) ?>"')) {
    throw new RuntimeException('placement rows must expose block type for system row styling');
}
if (!str_contains($view, '.feo-placement-table tbody tr[data-placement-block-type="exclusion"],.feo-placement-table tbody tr[data-placement-block-type="exclusion"]:hover{background:var(--danger-bg)}')) {
    throw new RuntimeException('exclusion rows must use the system light-pink danger background');
}

echo "feo_placement_hide_disabled_ui_contract: PASS\n";
