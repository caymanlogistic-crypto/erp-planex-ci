<?php

declare(strict_types=1);

$fixtureDsn = trim((string)getenv('PLANEX_FINANCE_TEST_DSN'));
$fixtureUser = trim((string)getenv('PLANEX_FINANCE_TEST_USER'));
$fixturePassword = (string)getenv('PLANEX_FINANCE_TEST_PASSWORD');
if ($fixtureDsn === '' || $fixtureUser === '' || $fixturePassword === '') {
    throw new RuntimeException('Explicit finance test database credentials are required.');
}
$fixedDb = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
if ($fixedDb !== '') {
    $expectedDsn = 'mysql:host=' . getenv('PLANEX_SCHEMA_DB_HOST') . ';port=' . getenv('PLANEX_SCHEMA_DB_PORT')
        . ';dbname=' . $fixedDb . ';charset=utf8mb4';
    if (getenv('APP_ENV') !== 'test'
        || !in_array($fixedDb, array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))), true)
        || $fixtureDsn !== $expectedDsn
        || $fixtureUser !== getenv('PLANEX_SCHEMA_DB_USER')
        || $fixturePassword !== getenv('PLANEX_SCHEMA_DB_PASSWORD')) {
        throw new RuntimeException('Finance test database does not match the guarded local lease.');
    }
}
$fixturePdo = new PDO($fixtureDsn, $fixtureUser, $fixturePassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fixturePdo->exec('DROP TABLE IF EXISTS bank_transactions');
unset($fixturePdo);

require __DIR__.'/finance_bank_invoice_settlement_behavioral_test.php';
require_once __DIR__.'/../app/Service/FinanceInvoicePaymentTimelineService.php';

use App\Service\FinanceInvoicePaymentTimelineService;

$timelines = FinanceInvoicePaymentTimelineService::buildForInvoices($pdo, [$clientInvoice, $carrierInvoice]);
$clientTimeline = $timelines[$clientInvoice] ?? null;
ok(is_array($clientTimeline), 'client timeline exists');
ok((int)$clientTimeline['planned_count'] === 1, 'client timeline has one planned route payment');
ok((string)$clientTimeline['planned_total'] === '300.00', 'client planned total is 300');
ok((int)$clientTimeline['fact_count'] === 1, 'two allocation parts from one bank operation display as one actual payment');
ok((string)$clientTimeline['paid_total'] === '300.00', 'client fact total is 300');
ok((string)$clientTimeline['remaining_total'] === '0.00', 'client timeline has no remaining amount');
ok(($clientTimeline['facts'][0]['actual_date'] ?? null) === '2026-12-03', 'fact uses bank operation date, not allocation creation date');
ok((string)($clientTimeline['facts'][0]['amount'] ?? '') === '300.00', 'actual payment is grouped to 300');
ok((string)$clientTimeline['late_paid_total'] === '300.00', 'late amount is computed from allocation portions');
ok((int)$clientTimeline['max_delay_days'] === 1, 'payment one day after due date reports one day delay');
ok((int)($clientTimeline['settlement_parts'][0]['delay_days'] ?? -99) === 1, 'settlement part contains delay');

$carrierTimeline = $timelines[$carrierInvoice] ?? null;
ok(is_array($carrierTimeline), 'carrier timeline exists');
ok((string)$carrierTimeline['paid_total'] === '250.00', 'carrier fact total is 250');
ok(($carrierTimeline['facts'][0]['actual_date'] ?? null) === '2026-12-04', 'carrier fact date comes from bank operation');
ok((int)$carrierTimeline['max_delay_days'] === 2, 'carrier payment delay is two days');

echo "FINANCE_INVOICE_PAYMENT_TIMELINE_OK\n";
