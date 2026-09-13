<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/DateCalculationService.php';
require_once __DIR__ . '/../app/Service/ProductionCalendarService.php';
require_once __DIR__ . '/../app/Service/FinanceObligationService.php';
require_once __DIR__ . '/../app/Service/FinanceInvoicePaymentTimelineService.php';

use App\Service\FinanceInvoicePaymentTimelineService;
use App\Service\FinanceObligationService;

function overdueGuard(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

final class OverdueSemanticsDummyPdo extends PDO
{
    public function __construct() {}
}

$pdo = new OverdueSemanticsDummyPdo();
$resolve = new ReflectionMethod(FinanceObligationService::class, 'resolveDates');
$computeStatus = new ReflectionMethod(FinanceObligationService::class, 'computeStatus');

$plannedOnly = [
    'planned_loading_date' => '2026-09-01',
    'planned_unloading_date' => '2026-09-02',
    'actual_loading_date' => null,
    'actual_unloading_date' => null,
    'closing_documents_received_date' => null,
];

$cases = [
    'start_day' => [null, '2026-09-01'],
    'after_start' => [7, '2026-09-08'],
    'end_day' => [null, '2026-09-02'],
    'after_end' => [7, '2026-09-09'],
];

foreach ($cases as $condition => [$days, $expectedForecast]) {
    $resolved = $resolve->invoke(null, $pdo, $plannedOnly, $condition, $days, 'calendar', null);
    overdueGuard($resolved['event_date'] === null, $condition . ' must not treat planned date as factual event');
    overdueGuard($resolved['due_date'] === null, $condition . ' must not create factual due_date before event');
    overdueGuard($resolved['forecast_due_date'] === $expectedForecast, $condition . ' must preserve planning forecast');
    overdueGuard($resolved['state'] === 'waiting_event', $condition . ' must wait for factual event');
    $status = $computeStatus->invoke(null, '100.00', '0.00', $resolved['due_date'], $resolved['state']);
    overdueGuard($status === FinanceObligationService::STATUS_WAITING_EVENT, $condition . ' must not become overdue from forecast');
}

$withFacts = $plannedOnly + [
    'actual_loading_date' => '2026-09-03',
    'actual_unloading_date' => '2026-09-04',
];
$withFacts['actual_loading_date'] = '2026-09-03';
$withFacts['actual_unloading_date'] = '2026-09-04';

$afterStart = $resolve->invoke(null, $pdo, $withFacts, 'after_start', 7, 'calendar', null);
overdueGuard($afterStart['event_date'] === '2026-09-03', 'after_start must use factual loading date');
overdueGuard($afterStart['due_date'] === '2026-09-10', 'after_start calendar offset must start after factual loading date');
overdueGuard($afterStart['state'] === 'ok', 'after_start factual event must resolve deadline');

$afterEnd = $resolve->invoke(null, $pdo, $withFacts, 'after_end', 7, 'calendar', null);
overdueGuard($afterEnd['event_date'] === '2026-09-04', 'after_end must use factual unloading date');
overdueGuard($afterEnd['due_date'] === '2026-09-11', 'after_end calendar offset must start after factual unloading date');
overdueGuard($afterEnd['state'] === 'ok', 'after_end factual event must resolve deadline');

$today = new DateTimeImmutable('2026-09-13');
$forecastOnly = FinanceInvoicePaymentTimelineService::deadlineState([
    'due_date' => null,
    'forecast_due_date' => '2026-09-09',
    'status' => FinanceObligationService::STATUS_WAITING_EVENT,
], $today);
overdueGuard($forecastOnly['display_date'] === '2026-09-09', 'forecast remains visible for planning');
overdueGuard($forecastOnly['waiting_event'] === true, 'forecast-only stage must remain waiting_event');
overdueGuard($forecastOnly['overdue_days'] === 0, 'forecast-only stage must never produce overdue days');

$dueToday = FinanceInvoicePaymentTimelineService::deadlineState([
    'due_date' => '2026-09-13',
    'forecast_due_date' => '2026-09-10',
    'status' => FinanceObligationService::STATUS_PLANNED,
], $today);
overdueGuard($dueToday['due_today'] === true, 'due date itself must not be overdue');
overdueGuard($dueToday['overdue_days'] === 0, 'due date itself must have zero overdue days');

$dueYesterday = FinanceInvoicePaymentTimelineService::deadlineState([
    'due_date' => '2026-09-12',
    'forecast_due_date' => '2026-09-10',
    'status' => FinanceObligationService::STATUS_OVERDUE,
], $today);
overdueGuard($dueYesterday['waiting_event'] === false, 'factual due date must resolve event wait');
overdueGuard($dueYesterday['overdue_days'] === 1, 'overdue starts on the day after factual due date');

$root = dirname(__DIR__);
$files = [
    'index' => (string) file_get_contents($root . '/app/Http/Controllers/Company/InvoiceActions/index.php'),
    'view' => (string) file_get_contents($root . '/app/View/pages/company_finance_invoices.php'),
    'modal' => (string) file_get_contents($root . '/app/View/partials/company_invoice_modal_view.php'),
    'payables' => (string) file_get_contents($root . '/app/Service/FinancePayablesReportService.php'),
    'dashboard' => (string) file_get_contents($root . '/app/Service/FinanceDashboardService.php'),
    'plan_fact' => (string) file_get_contents($root . '/app/Service/FinancePaymentPlanFactService.php'),
];

overdueGuard(str_contains($files['index'], 'CASE WHEN o.due_date IS NOT NULL'), 'invoice summary must resolve only factual due dates');
overdueGuard(str_contains($files['view'], 'FinanceInvoicePaymentTimelineService::deadlineState'), 'invoice stages must use centralized deadline semantics');
overdueGuard(str_contains($files['view'], "'Прогноз:'"), 'forecast-only stage must be labelled as forecast');
overdueGuard(str_contains($files['modal'], '$due = trim((string)($link[\'due_date\'] ?? \'\'));'), 'invoice modal must not promote forecast to factual due date');
overdueGuard(str_contains($files['modal'], "'прогноз '"), 'invoice modal must label forecast explicitly');
overdueGuard(str_contains($files['payables'], '$due = self::validDate($row[\'due_date\'] ?? null);'), 'payables overdue aging must use factual due date only');
overdueGuard(str_contains($files['dashboard'], "FinanceObligationService::STATUS_OVERDUE_PARTIAL"), 'dashboard overdue flow must use canonical obligation overdue status');
overdueGuard(!str_contains($files['dashboard'], 'COALESCE(fi.planned_payment_date,fi.invoice_date)<?'), 'dashboard must not use invoice date as overdue deadline');
overdueGuard(str_contains($files['plan_fact'], "'overdue_date' => \$obligation['due_date'] ?? null"), 'plan/fact overdue state must separate factual due from forecast');
overdueGuard(str_contains($files['plan_fact'], "o.status IN ('overdue','overdue_partial')"), 'linked invoice plan/fact overdue state must follow current overdue obligations');
overdueGuard(str_contains((string) file_get_contents($root . '/app/View/pages/company_finance_payables.php'), "'Прогноз '"), 'payables must label forecast separately from factual due date');
overdueGuard(str_contains((string) file_get_contents($root . '/app/View/pages/company_finance_receivables.php'), "'Прогноз '"), 'receivables must label forecast separately from factual due date');

echo "FINANCE_OVERDUE_EVENT_SEMANTICS_OK\n";
