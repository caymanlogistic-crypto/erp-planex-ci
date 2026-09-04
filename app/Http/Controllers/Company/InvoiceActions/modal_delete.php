<?php

use App\Core\Database;
use App\Service\FinanceInvoiceService;

verifyCsrfRequest();

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
$error = null;

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);

    if (!$company || $company['status'] !== 'active') {
        throw new \RuntimeException('Компания недоступна.');
    }

    $localConfig = companyDatabaseConfig($config, $company);
    $localDb = new Database($localConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    $invoiceId = (int) $id;
    $user = $_SESSION['user'] ?? [];
    $userId = (int) ($user['user_id'] ?? 0);
    $roleCode = (string) ($user['role_code'] ?? '');

    $reason = (string) ($_POST['reason'] ?? '');
    $trimmedReason = trim($reason);
    if ($trimmedReason === '') {
        throw new \RuntimeException('Укажите причину аннулирования.');
    }

    FinanceInvoiceService::cancelInvoice($localPdo, $invoiceId, $userId, $roleCode, $trimmedReason);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

if ($isAjax) {
    if ($error) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="form-alert alert-error">' . e($error) . '</div>';
        exit;
    }
    $invoice = FinanceInvoiceService::fetchInvoiceById($localPdo, $invoiceId);
    $links = FinanceInvoiceService::fetchInvoiceLinks($localPdo, $invoiceId);
    $canEdit = true;
    $canDelete = true;
    require base_path('app/View/partials/company_invoice_modal_view.php');
    exit;
}

if ($error) {
    echo '<div class="form-alert alert-error">Ошибка: ' . e($error) . '</div>';
    exit;
}

$_SESSION['invoice_success'] = 'Счёт аннулирован.';

$redirectUrl = app_url('/company/finance/invoices');
header('Location: ' . $redirectUrl);
exit;
