<?php

declare(strict_types=1);
spl_autoload_register(static function(string $class): void { if(str_starts_with($class,'App\\')){ $file=dirname(__DIR__,2).'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file))require_once $file; } });

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function app_url(string $path): string { return '/erpv2' . $path; }
function base_path(string $path): string { return dirname(__DIR__, 2) . '/' . $path; }

$payment = static fn(int $amount, int $invoiceId = 0): array => [
    'amount' => (string)$amount,
    'payment_date' => '2026-09-30',
    'invoice_id' => $invoiceId,
];
$invoice = static fn(int $id, int $amount): array => [
    'id' => $id,
    'number' => (string)$id,
    'invoice_date' => '2026-09-29',
    'amount' => (string)$amount,
];
$side = static fn(?int $rate, array $payments = [], array $invoices = []): array => [
    'rate' => $rate === null ? null : (string)$rate,
    'payments' => $payments,
    'invoices' => $invoices,
];
$trip = static fn(int $id, array $customer, array $carrier, ?array $planned = null, ?array $carrierPlan = null): array => [
    'business_id' => $id,
    'comment' => 'Рейс ' . $id,
    'actual_start_date' => '2026-09-28',
    'planned_start_date' => null,
    'planned_start_date_from' => null,
    'actual_end_date' => '2026-09-29',
    'planned_finish_date' => null,
    'client_id' => 1,
    'client_name' => 'Заказчик',
    'carrier_id' => 2,
    'carrier_name' => 'Перевозчик',
    'driver_full_name' => $id === 104 ? null : 'Иванов Иван Иванович',
    'planned_receipt' => $planned,
    'planned_carrier_payment' => $carrierPlan,
    'sides' => ['customer' => $customer, 'carrier' => $carrier],
];
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow'));
$past = $today->modify('-1 day')->format('Y-m-d');
$dueToday = $today->format('Y-m-d');
$future = $today->modify('+2 days')->format('Y-m-d');
$trips = array_reverse([
    $trip(101, $side(100, [$payment(100, 1)], [$invoice(1, 100)]), $side(80, [$payment(80, 2)], [$invoice(2, 80)]), ['date' => $past, 'due_date' => $past, 'remaining' => false, 'status' => 'ready']),
    $trip(102, $side(100, [$payment(40)], [$invoice(3, 70), $invoice(4, 30)]), $side(80, [$payment(20)], [$invoice(5, 80)]), ['date' => $dueToday, 'due_date' => $dueToday, 'remaining' => true, 'status' => 'ready']),
    $trip(103, $side(100), $side(80), ['date' => $past, 'due_date' => $past, 'remaining' => true, 'status' => 'ready'], ['date' => $past, 'due_date' => $past, 'remaining' => true, 'status' => 'ready']),
    $trip(104, $side(100, [$payment(100)]), $side(null), ['date' => $future, 'due_date' => null, 'remaining' => true, 'status' => 'waiting_event']),
    $trip(105, $side(0), $side(80, [$payment(80)])),
]);
foreach ($trips as &$row) {
    $row['closing_payment'] = match ((int)$row['business_id']) {
        102 => ['status' => 'incomplete', 'missing' => ['АКТ/УПД'], 'processed' => 1, 'total' => 2, 'tn_issues' => ['Заявка №502: нет скана ТН']],
        104 => ['status' => 'incomplete', 'missing' => ['Подписанный договор / заявка'], 'processed' => 0, 'total' => 0, 'tn_issues' => []],
        default => ['status' => 'ready', 'missing' => [], 'processed' => 1, 'total' => 1, 'tn_issues' => []],
    };
}
unset($row);
$localPdo = null;
$dbError = null;
$_SESSION = [];

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>';
echo '<div class="app"><header class="topbar"><div class="topbar-brand"><div class="logo-mark"></div><span class="brand-name">ERP PLANEX</span><span class="brand-tag">ERP</span></div><div class="topbar-crumbs"><b>КОМПАНИЯ</b></div></header><aside class="sidebar" aria-label="Основная навигация"><div class="nav-group"><div class="nav-section-label">ОПЕРАЦИИ</div><a class="nav-item" href="#">Клиенты</a><a class="nav-item" href="#">Рейсы</a><a class="nav-item" href="#">Финансы</a></div></aside><main class="content content--wide-workspace"><div class="workspace-shell-1690">';
require __DIR__ . '/../../app/View/pages/company_finance_received_paid.php';
echo '</div></main></div></body></html>';
