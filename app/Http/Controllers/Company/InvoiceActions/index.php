<?php

use App\Core\Database;
use App\Service\FinanceInvoicePaymentTimelineService;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;


$pageTitle = 'Счета';
$pageContext = 'Финансы › Счета';

$companyId = (int)(getSessionCompanyId() ?? 0);
$company = null;
$localPdo = null;
$dbError = null;
$invoices = [];
$paymentTimelines = [];
$invPage = 1;
$invPerPage = 100;
$invTotal = 0;
$invPages = 1;
$direction = $_GET['direction'] ?? '';

if ($companyId <= 0) {
    ob_start();
    require base_path('app/View/pages/company_finance_invoices.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $pdo = $db->connection();
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        $company = null;
        $dbError = 'Компания не найдена.';
    } elseif (($company['status'] ?? '') !== 'active') {
        $company = null;
        $dbError = 'Компания неактивна.';
    } else {
        $pageContext = 'Финансы › Счета › Компания: ' . $company['name'];
        $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
        applyLocalMigrations($localPdo);
        FinanceObligationService::syncAllOperationalObligations($localPdo);

        $user = $_SESSION['user'] ?? [];
        $allowedDirection = in_array($direction, [FinanceInvoiceService::DIRECTION_OUTGOING, FinanceInvoiceService::DIRECTION_INCOMING], true)
            ? $direction
            : null;
        $invPage = max(1, (int)($_GET['page'] ?? 1));
        $invPerPage = max(1, min(500, (int)($_GET['per_page'] ?? 100)));
        $invResult = FinanceInvoiceService::fetchInvoices($localPdo, $user, $allowedDirection, $invPage, $invPerPage);
        $invoices = $invResult['data'];
        $invTotal = $invResult['total'];
        $invPages = $invResult['pages'];

        $deadlineSummary = [];
        $ids = [];
        if ($invoices !== [] && FinanceObligationService::schemaReady($localPdo)) {
            $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $invoices)));
            if ($ids !== []) {
                $paymentTimelines = FinanceInvoicePaymentTimelineService::buildForInvoices($localPdo, $ids);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $summaryStmt = $localPdo->prepare(
                    "SELECT l.invoice_id,
                            COUNT(DISTINCT l.obligation_id) AS obligation_count,
                            COUNT(DISTINCT CASE WHEN COALESCE(o.due_date,o.forecast_due_date) IS NOT NULL THEN l.obligation_id END) AS resolved_count,
                            MIN(COALESCE(o.due_date,o.forecast_due_date)) AS due_min,
                            MAX(COALESCE(o.due_date,o.forecast_due_date)) AS due_max,
                            MAX(CASE WHEN o.status IN ('overdue','overdue_partial') THEN 1 ELSE 0 END) AS has_overdue
                       FROM finance_invoice_links l
                       JOIN finance_obligations o ON o.id=l.obligation_id AND o.cancelled_at IS NULL
                      WHERE l.invoice_id IN ($placeholders)
                      GROUP BY l.invoice_id"
                );
                $summaryStmt->execute($ids);
                foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $summaryRow) {
                    $deadlineSummary[(int)$summaryRow['invoice_id']] = $summaryRow;
                }
            }
        }

        foreach ($invoices as &$invoice) {
            $invoiceId = (int)($invoice['id'] ?? 0);
            $summary = $deadlineSummary[$invoiceId] ?? null;
            $obligationCount = (int)($summary['obligation_count'] ?? 0);
            $resolvedCount = (int)($summary['resolved_count'] ?? 0);
            if ($obligationCount === 0) {
                $invoice['display_due_text'] = '—';
            } elseif ($resolvedCount === 0) {
                $invoice['display_due_text'] = 'Ожидается событие';
            } elseif ($resolvedCount === $obligationCount && ($summary['due_min'] ?? null) === ($summary['due_max'] ?? null)) {
                $invoice['display_due_text'] = (string)$summary['due_min'];
            } else {
                $invoice['display_due_text'] = 'Несколько сроков';
            }

            $invoice['payment_timeline'] = $paymentTimelines[$invoiceId] ?? null;

            $status = (string)($invoice['status'] ?? '');
            $amount = (float)($invoice['amount'] ?? 0);
            $paid = (float)($invoice['paid_amount'] ?? 0);
            $remaining = (float)($invoice['remaining_amount'] ?? 0);
            if ($status === 'draft') {
                $status = (string)$invoice['direction'] === FinanceInvoiceService::DIRECTION_INCOMING ? 'received' : 'issued';
            }
            if ($status !== 'cancelled' && $amount > 0 && $remaining <= 0.00001 && $paid >= $amount - 0.00001) {
                $status = 'paid';
            } elseif (!in_array($status, ['cancelled', 'paid'], true) && (int)($summary['has_overdue'] ?? 0) === 1) {
                $status = $paid > 0 ? 'overdue_partial' : 'overdue';
            } elseif (!in_array($status, ['cancelled', 'paid'], true)
                && $paid > 0
                && $remaining > 0) {
                $status = 'partially_paid';
            }
            $invoice['display_status'] = $status;
        }
        unset($invoice);
    }
} catch (Throwable $e) {
    $company = $company ?? null;
    $dbError = 'Ошибка загрузки данных: ' . $e->getMessage();
}

ob_start();
require base_path('app/View/pages/company_finance_invoices.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');