<?php

declare(strict_types=1);

$view = (string) file_get_contents(__DIR__ . '/../app/View/pages/company_feo_map_placement.php');

if (!str_contains($view, "preg_replace('/\\s*,\\s*/u', ', ', \$sourceText)")) {
    throw new RuntimeException('manual request source must normalize comma separators with a following space');
}
if (!str_contains($view, '.feo-placement-source-cell{white-space:normal!important;overflow-wrap:anywhere;word-break:normal;text-overflow:clip!important}')) {
    throw new RuntimeException('manual request source cell must allow wrapping');
}
if (!str_contains($view, 'class="feo-placement-source-cell"')) {
    throw new RuntimeException('manual request source cell class must be rendered');
}

echo "feo_placement_source_wrap_ui_contract: PASS\n";
