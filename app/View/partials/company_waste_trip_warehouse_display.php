<?php
$warehouseLabel = \App\Service\WasteTripLegacyMetadataService::warehouseLabel($trip);
if ($warehouseLabel === null || trim($warehouseLabel) === '') {
    echo '—';
} else {
    $warehouseParts = preg_split('/\R/u', trim($warehouseLabel));
    foreach ($warehouseParts as $index => $part) {
        if (trim($part) === '') continue;
        if ($index > 0) echo '<br>';
        $part = str_replace(['Выгрузка на склад:', 'Загрузка со склада:'], ['Выгрузка:', 'Загрузка:'], trim($part));
        echo str_ends_with($part, ':') ? e($part) : '<span class="waste-warehouse-city">' . e($part) . '</span>';
    }
}
