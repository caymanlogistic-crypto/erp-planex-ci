<?php
declare(strict_types=1);
$view = (string) file_get_contents(__DIR__ . '/../app/View/pages/company_feo_map_placement.php');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/css/registry-components.css');
$legacyCss = (string) file_get_contents(__DIR__ . '/../public/assets/css/app.css');
if (!str_contains($view, 'data-placement-hide-disabled data-registry-field="hide-disabled" data-registry-default="true" checked')) throw new RuntimeException('hide-disabled checkbox must be checked by default');
if (!str_contains($view, 'Не показывать отключённые')) throw new RuntimeException('hide-disabled checkbox label is missing');
if (!str_contains($view, 'data-placement-block-status="<?= $statusApplied ? \'applied\' : \'disabled\' ?>"')) throw new RuntimeException('placement rows must expose applied/disabled status');
if (!str_contains($view, '.feo-placement-table-card:has([data-placement-hide-disabled]:checked) .feo-placement-table tbody tr[data-placement-block-status="disabled"]{display:none}')) throw new RuntimeException('checked hide-disabled control must hide disabled rows');
if (!str_contains($view, 'class="registry-ui-checkbox"') || !str_contains($css, 'accent-color:var(--registry-checkbox-accent)')) throw new RuntimeException('hide-disabled checkbox must use the approved brown role');
if (!str_contains($view, 'data-placement-block-type="<?= e((string)($block[\'block_type\'] ?? \'\')) ?>"')) throw new RuntimeException('placement rows must expose block type for system row styling');
if (str_contains($legacyCss, '.feo-placement-table tbody tr[data-placement-block-type="exclusion"] > td')) throw new RuntimeException('exclusion row must not override approved registry cell backgrounds');
echo "feo_placement_hide_disabled_ui_contract: PASS\n";
