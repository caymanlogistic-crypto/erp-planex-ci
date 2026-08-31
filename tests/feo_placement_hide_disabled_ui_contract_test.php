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

echo "feo_placement_hide_disabled_ui_contract: PASS\n";
