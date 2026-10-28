<?php
declare(strict_types=1);

// Synthetic, database-free renderer for the received/paid Design Lab page.
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require_once $path;
});
function base_path(string $path = ''): string { global $root; return $root . ($path === '' ? '' : '/' . ltrim($path, '/')); }
function app_url(string $path): string { return $path; }
function app_base_path(): string { return ''; }
function current_app_path(): string { return '/company/finance/received-paid'; }
function csrfToken(): string { return 'DESIGN-LAB-REDACTED'; }
function csrfField(): string { return '<input type="hidden" name="_csrf_token" value="DESIGN-LAB-REDACTED">'; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$_SESSION = ['role_code' => 'company_owner', 'company_id' => 0, 'user_id' => 1, 'user_name' => 'GTP-Бухгалтерия'];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(1, 0, array_column(\App\Security\PageRegistry::companyPages(), 'key')));
$company = ['name' => 'ООО «ПЛАНЭКС»'];
$config = ['app' => ['app_name' => 'ERP_PLANEX']];
$pageTitle = 'Получено/оплачено';
$pageContext = 'Финансы › Получено/оплачено';
$pageStylesheets = ['/assets/css/finance-received-paid.css'];
$topbarCrumbs = [['label' => 'ООО "ПЛАНЭКС"', 'url' => '/company/dashboard'], ['label' => 'Финансы', 'url' => null], ['label' => 'Получено/оплачено', 'url' => null]];
$localPdo = null;
$dbError = null;

$invoice = static fn(int $id, int $amount): array => [
    'id' => $id, 'number' => (string)$id, 'invoice_date' => '2026-10-20', 'amount' => (string)$amount,
];
$payment = static fn(int $amount, int $invoiceId = 0): array => [
    'amount' => (string)$amount, 'payment_date' => '2026-10-23', 'invoice_id' => $invoiceId,
];
$side = static fn(?int $rate, array $payments = [], array $invoices = []): array => [
    'rate' => $rate === null ? null : (string)$rate,
    'payments' => $payments,
    'invoices' => $invoices,
];
$trip = static fn(int $id, string $description, string $carrier, array $customer, array $carrierSide, ?array $carrierPlan = null): array => [
    'id' => $id + 1000,
    'business_id' => $id,
    'comment' => $description,
    'actual_start_date' => '2026-10-19',
    'planned_start_date' => null,
    'planned_start_date_from' => null,
    'actual_end_date' => '2026-10-21',
    'planned_finish_date' => null,
    'client_id' => 1,
    'client_name' => 'ООО «СтройПром»',
    'carrier_id' => 2,
    'carrier_name' => $carrier,
    'driver_full_name' => 'Иванов Иван Иванович',
    'planned_receipt' => null,
    'planned_carrier_payment' => $carrierPlan,
    'sides' => ['customer' => $customer, 'carrier' => $carrierSide],
];
$plan = ['date' => '2026-10-29', 'due_date' => '2026-10-29', 'remaining' => true, 'status' => 'ready'];
$trips = [
    $trip(426, 'Вывоз отходов · Северный район', 'ИП Миронова Ольга Сергеевна', $side(120000, [$payment(120000, 9101)], [$invoice(9101, 120000)]), $side(85000, [], [$invoice(9201, 85000)]), $plan),
    $trip(425, 'Перевозка грунта · Промзона', 'ООО «Северный транспорт»', $side(94000, [$payment(40000, 9102)], [$invoice(9102, 94000)]), $side(68000, [$payment(18000, 9202)], [$invoice(9202, 68000)]), $plan),
    $trip(424, 'Доставка материалов · Склад 3', 'ИП Данилов Павел Викторович', $side(76000, [], [$invoice(9103, 76000)]), $side(51000, [], [$invoice(9203, 51000)]), $plan),
    $trip(423, 'Рейс по Московской области', 'ООО «Горизонт Логистик»', $side(112000, [$payment(112000, 9104)], [$invoice(9104, 112000)]), $side(75000, [$payment(75000, 9204)], [$invoice(9204, 75000)])),
    $trip(422, 'Вывоз грунта · Южный район', 'ИП Беляев Артём Игоревич', $side(59000, [$payment(59000)], []), $side(null)),
];
$trips[0]['closing_payment'] = ['status' => 'ready', 'missing' => [], 'processed' => 2, 'total' => 2, 'tn_issues' => []];
$trips[1]['closing_payment'] = ['status' => 'incomplete', 'missing' => ['Подписанный договор / заявка', 'АКТ/УПД'], 'processed' => 1, 'total' => 3, 'tn_issues' => ['Заявка №513: нет даты или времени']];
$trips[2]['closing_payment'] = ['status' => 'ready', 'missing' => [], 'processed' => 2, 'total' => 2, 'tn_issues' => []];
$trips[3]['closing_payment'] = ['status' => 'ready', 'missing' => [], 'processed' => 1, 'total' => 1, 'tn_issues' => []];
$trips[4]['closing_payment'] = ['status' => 'incomplete', 'missing' => ['Счёт'], 'processed' => 0, 'total' => 0, 'tn_issues' => []];
$descriptions = [
    'Вывоз строительных отходов', 'Перевозка грунта', 'Доставка материалов',
    'Вывоз снега', 'Развоз контейнеров', 'Работа на площадке',
    'Погрузка и перевозка', 'Перемещение оборудования', 'Рейс на полигон',
];
$carriers = [
    'ООО «Транзит»', 'ИП Ковалёва Марина Андреевна', 'ООО «ЭкоМаршрут»',
    'ИП Сидоров Константин Львович', 'ООО «Вектор»', 'ИП Морозов Алексей Юрьевич',
];
for ($index = 0; $index < 35; $index++) {
    $id = 421 - $index;
    $rate = 46000 + ($index % 9) * 7200;
    $paid = match ($index % 4) { 0 => $rate, 1 => (int)($rate / 3), default => 0 };
    $trips[] = $trip(
        $id,
        $descriptions[$index % count($descriptions)] . ' · ' . ['Север', 'Центр', 'Юг', 'Восток'][$index % 4],
        $carriers[$index % count($carriers)],
        $side($rate + 24000, $index % 3 === 0 ? [$payment($rate + 24000, 10000 + $id)] : [], [$invoice(10000 + $id, $rate + 24000)]),
        $side($rate, $paid ? [$payment($paid, 20000 + $id)] : [], [$invoice(20000 + $id, $rate)]),
        $plan,
    );
    $trips[array_key_last($trips)]['closing_payment'] = $index % 3 === 0
        ? ['status' => 'incomplete', 'missing' => ['АКТ/УПД'], 'processed' => 0, 'total' => 2, 'tn_issues' => ['Заявка №' . (600 + $index) . ': нет скана ТН']]
        : ['status' => 'ready', 'missing' => [], 'processed' => 1, 'total' => 1, 'tn_issues' => []];
}

ob_start();
require $root . '/app/View/pages/company_finance_received_paid.php';
$content = (string)ob_get_clean();
$clients = [['id' => 1, 'name' => 'ООО «СтройПром»', 'inn' => '7700000000']];
$contractors = [['id' => 2, 'name' => 'ИП Миронова Ольга Сергеевна', 'inn' => '770000000001']];
$isEdit = false;
$invoice = ['direction' => 'INCOMING'];
$returnTo = '/company/finance/received-paid';
ob_start();
require $root . '/app/View/partials/company_invoice_create_form.php';
$form = (string)ob_get_clean();
$content = str_replace('data-open-modal="invoice-create-modal" disabled', 'data-open-modal="invoice-create-modal"', $content);
$content = preg_replace_callback(
    '~(id="invoice-create-modal".*?<div class="modal-head">.*?</div>)(\s*)(</div></div>)~s',
    static fn(array $match): string => $match[1] . $form . $match[3],
    $content,
    1,
);
require $root . '/app/View/layouts/main.php';
