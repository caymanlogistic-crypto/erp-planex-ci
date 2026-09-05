<?php

use App\Core\Database;
use App\Service\FinanceCashInvoiceEventService;
use App\Service\FinanceCashService;

verifyCsrfRequest();

$companyId = (int)(getSessionCompanyId() ?? 0);
$invoiceId = (int)$id;

try {
    if ($companyId <= 0 || $invoiceId <= 0) {
        throw new InvalidArgumentException('Компания или счёт не найдены.');
    }
    $company = $db->fetch('SELECT * FROM companies WHERE id=?', [$companyId]);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Компания недоступна.');
    }

    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($localPdo);

    $actor = [
        'id'=>(int)($_SESSION['user_id'] ?? 0) ?: null,
        'role'=>(string)($_SESSION['role_code'] ?? 'company_owner'),
        'user_id'=>(int)($_SESSION['user_id'] ?? 0) ?: null,
        'role_code'=>(string)($_SESSION['role_code'] ?? 'company_owner'),
    ];
    $payload = [
        'invoice_id'=>$invoiceId,
        'amount'=>(string)($_POST['amount'] ?? ''),
        'operation_date'=>(string)($_POST['operation_date'] ?? ''),
        'comment'=>trim((string)($_POST['comment'] ?? '')),
    ];
    $result = FinanceCashInvoiceEventService::payCarrierInvoiceFromMainCash($localPdo, $payload, $actor);
    $_SESSION['finance_success'] = 'Из Основной кассы оплачено ' . FinanceCashService::formatAmount($result['amount'] ?? null)
        . ' ₽ по входящему счёту ' . ($result['invoice_number'] ?? ('#'.$invoiceId)) . '.';
} catch (InvalidArgumentException|RuntimeException $e) {
    $_SESSION['finance_error'] = $e->getMessage();
} catch (Throwable $e) {
    $_SESSION['finance_error'] = 'Не удалось провести оплату: ' . $e->getMessage();
}

redirect_to('/company/finance/payables');
