<?php

$root = dirname(__DIR__);
$bootstrap = (string) file_get_contents($root . '/app/View/partials/company_linear_trip_services_bootstrap.php');
$registryHover = (string) file_get_contents($root . '/public/assets/js/linear-trip-document-delete.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(!str_contains($bootstrap, "table.tHead.rows[0].innerHTML"), 'service rows must not rebuild the canonical registry header');
$assert(!str_contains($bootstrap, "['ID','Начало рейса','Окончание рейса','Заказчик','Услуга'"), 'registry must not add a dedicated service column');
$assert(str_contains($bootstrap, "for(var commonIndex=0;commonIndex<4;commonIndex++)"), 'first four canonical columns must stay common across service rows');
$assert(str_contains($bootstrap, "for(var primaryIndex=4;primaryIndex<9;primaryIndex++)"), 'primary transport row must preserve all five canonical detail columns');
foreach ([
    "executor.className='registry-executor'",
    "details.className='registry-route trip-service-desc'",
    "cargo.className='registry-cargo'",
    "customerRate.className='registry-rate'",
    "serviceRate.className='registry-rate'",
] as $token) {
    $assert(str_contains($bootstrap, $token), 'additional service row must stay inside the canonical 9-column grid: ' . $token);
}
$assert(str_contains($bootstrap, ".linear-trip-registry.is-service-grid td{vertical-align:top}"), 'service rows may align body cells without changing canonical header alignment');
$assert(!str_contains($bootstrap, ".linear-trip-registry.is-service-grid th,.linear-trip-registry.is-service-grid td"), 'service mode must not override canonical header alignment');
foreach ([
    "is-route-hover",
    "var(--table-row-hover)",
    "data-linear-route-id",
    "routeHoverReady",
    "body.addEventListener('mouseover'",
    "body.addEventListener('mouseout'",
    "row.dataset.linearRouteId === routeId",
] as $token) {
    $assert(str_contains($registryHover, $token), 'registry route-group hover contract missing: ' . $token);
}

fwrite(STDOUT, "linear_trip_registry_service_layout_contract: PASS\n");
