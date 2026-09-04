<?php

use App\Core\Database;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceInvoiceSettlementHistoryService;
use App\Service\FinanceObligationService;


$invoice = null;
$links = [];
$settlements = [];
$error = null;

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Компания недоступна.');
    }

    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($localPdo);
    FinanceObligationService::syncAllLinearRoutes($localPdo);

    $invoiceId = (int)$id;
    $invoice = FinanceInvoiceService::fetchInvoiceById($localPdo, $invoiceId);
    if (!$invoice) {
        http_response_code(404);
        throw new RuntimeException('Счёт не найден.');
    }
    $links = FinanceObligationService::invoiceLinks($localPdo, $invoiceId);
    $settlements = FinanceInvoiceSettlementHistoryService::forInvoice($localPdo, $invoiceId);

    $isCancelled = ($invoice['cancelled_at'] ?? null) !== null || ($invoice['status'] ?? '') === 'cancelled';
    $canEdit = !$isCancelled;
    $canDelete = true;
    $canPayCarrier = !$isCancelled
        && (string)($invoice['direction'] ?? '') === FinanceInvoiceService::DIRECTION_INCOMING
        && (float)($invoice['remaining_amount'] ?? 0) > 0;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require base_path('app/View/partials/company_invoice_modal_view.php');
