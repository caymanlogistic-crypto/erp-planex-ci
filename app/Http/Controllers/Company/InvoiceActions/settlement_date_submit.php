<?php

use App\Core\Database;
use App\Service\FinanceInvoiceSettlementDateService;

verifyCsrfRequest();
header('Content-Type: application/json; charset=utf-8');

$companyId = (int)(getSessionCompanyId() ?? 0);
$invoiceId = (int)$id;
$settlementOperationId = (int)$operationId;

try {
    if ($companyId <= 0 || $invoiceId <= 0 || $settlementOperationId <= 0) {
        throw new InvalidArgumentException('Компания, счёт или оплата не найдены.');
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
    $result = FinanceInvoiceSettlementDateService::updateDate(
        $localPdo,
        $invoiceId,
        $settlementOperationId,
        (string)($_POST['operation_date'] ?? ''),
        $actor
    );

    echo json_encode([
        'ok'=>true,
        'changed'=>(bool)($result['changed'] ?? false),
        'operation_date'=>(string)($result['operation_date'] ?? ''),
        'message'=>(bool)($result['changed'] ?? false)
            ? 'Дата фактической оплаты изменена.'
            : 'Дата фактической оплаты уже указана верно.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException|RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Не удалось изменить дату фактической оплаты.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
