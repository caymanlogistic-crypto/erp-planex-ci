<?php
declare(strict_types=1);

/**
 * Named Tabler Icons outline assets for registry page headers.
 * Source: @tabler/icons 3.48.0, icons/outline/<name>.svg
 * https://github.com/tabler/tabler-icons/tree/v3.48.0/icons/outline
 * MIT notice: app/View/components/tabler/LICENSE.txt
 * Page roles and named assets: docs/ui/approved-components.json header_icon.
 * Geometry stays byte-for-byte in the vendored SVG; only root presentation
 * attributes are augmented to use the approved shared header CSS.
 */
function registry_tabler_header_icon(string $name): string
{
    $allowed = ['users-group' => true, 'truck-delivery' => true, 'arrow-right-square' => true, 'arrows-exchange' => true, 'file-text' => true, 'user' => true, 'truck' => true, 'map-pin' => true];
    if (!isset($allowed[$name])) {
        throw new InvalidArgumentException('Unknown registry Tabler icon');
    }
    $source = file_get_contents(__DIR__ . '/tabler/outline/' . $name . '.svg');
    if ($source === false) {
        throw new RuntimeException('Registry Tabler icon asset is missing');
    }
    $class = 'class="icon icon-tabler icons-tabler-outline icon-tabler-' . $name . '"';
    if (substr_count($source, $class) !== 1) {
        throw new RuntimeException('Registry Tabler icon source is invalid');
    }
    return str_replace($class, substr($class, 0, -1) . ' page-head-tabler-icon" aria-hidden="true" focusable="false"', $source);
}
