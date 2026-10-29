<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once dirname(__DIR__).'/app/Service/DocumentGeneratorService.php';
require_once dirname(__DIR__).'/app/Service/DocumentTemplateService.php';

use App\Service\DocumentGeneratorService as Generator;
use App\Service\DocumentGeneratorWarehouseUnloadingService as Unloading;
use App\Service\DocumentTemplateService as Template;

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$rows = Unloading::apply([['zayavka_id'=>'300001','adres_vygruzki_otkhodov'=>'Старый адрес']], 'Адрес склада');
$check($rows[0]['document_unloading_address'] === 'Адрес склада', 'Warehouse unloading replaces document address');
$route = new ReflectionMethod(Generator::class, 'clientRouteDescription');
$description = $route->invoke(null, [
    'requests'=>[
        ['document_loading_address'=>'Склад Север'],
        ['mno_adres_pogruzki'=>'Прямой адрес'],
        ['document_loading_address'=>'Склад Север'],
    ],
    'requests_summary'=>['unload_address'=>'Склад Юг'],
]);
$check($description === 'Место загрузки: Склад Север; Прямой адрес. Место выгрузки: Склад Юг.', 'Client route covers both ends and deduplicates loading');
$materialize = new ReflectionMethod(Generator::class, 'materializeBuiltInTemplate');
foreach (['contract_vehicle_lease_crew_v9.docx'=>1, 'client_upd_contract_v9.docx'=>1, 'client_upd_invoice_v5.docx'=>0, 'client_upd_status2_v9.docx'=>0] as $name=>$repeatRows) {
    $path = $materialize->invoke(null, $name);
    try {
        $validation = Template::validateDocxFile($path);
        $check(($validation['ok'] ?? false) === true, $name.' structure');
        $check((int)($validation['repeat_rows'] ?? -1) === $repeatRows, $name.' request row contract');
        if (str_starts_with($name, 'client_upd_invoice_') || str_starts_with($name, 'client_upd_status2_')) {
            $check(!in_array('service.route', $validation['placeholders'] ?? [], true), $name.' excludes route from printed service');
        }
    } finally {
        @unlink($path);
    }
}
echo "DOCUMENT_GENERATOR_WAREHOUSE_TEMPLATE_CONTRACT=PASS\n";
