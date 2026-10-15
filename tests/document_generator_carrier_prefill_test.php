<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/app/Service/DocumentGeneratorService.php';

use App\Service\DocumentGeneratorService;

$expected = static function (array $trip, array $want): void {
    $actual = DocumentGeneratorService::suggestCarrierContractDefaults($trip);
    if ($actual !== $want) throw new RuntimeException('Carrier contract defaults: ' . json_encode([$actual, $want]));
};
$base = [
    'planned_start_date' => '2026-10-10',
    'actual_start_date' => '2026-10-12',
    'legal_entity_code' => 'planex',
    'no_markup' => 0,
    'carrier_payments' => [['amount' => '100000.50'], ['amount' => '20000.25']],
    'customer_payments' => [['amount' => '150000.00'], ['amount' => '30000.00']],
];
$expected($base, ['contract_date'=>'2026-10-12','start_date'=>'2026-10-12','finish_date'=>'2026-10-19','price'=>'120000.75']);
$expected(array_replace($base, ['actual_start_date'=>null]), ['contract_date'=>'2026-10-10','start_date'=>'2026-10-10','finish_date'=>'2026-10-17','price'=>'120000.75']);
$expected(array_replace($base, ['no_markup'=>1]), ['contract_date'=>'2026-10-12','start_date'=>'2026-10-12','finish_date'=>'2026-10-19','price'=>'180000.00']);
$expected(array_replace($base, ['legal_entity_code'=>'mg_logistic']), ['contract_date'=>'2026-10-12','start_date'=>'2026-10-12','finish_date'=>'2026-10-19','price'=>'180000.00']);
$expected(array_replace($base, ['actual_start_date'=>null,'planned_start_date'=>null,'planned_start_date_from'=>'2026-12-28','carrier_payments'=>[]]), ['contract_date'=>'2026-12-28','start_date'=>'2026-12-28','finish_date'=>'2027-01-04','price'=>'']);
echo "DOCUMENT_GENERATOR_CARRIER_PREFILL=PASS\n";
