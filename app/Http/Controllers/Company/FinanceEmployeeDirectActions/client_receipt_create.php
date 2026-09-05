<?php

use App\Core\Database;
use App\Service\FinanceEmployeeClientReceiptService;
use App\Service\FinanceEmployeePaymentService;

verifyCsrfRequest();

$employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) throw new RuntimeException('Компания не найдена.');
    $central = $db->connection();
    $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) throw new RuntimeException('Компания не найдена или неактивна.');
    $pdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    $employee = FinanceEmployeePaymentService::resolveActiveEmployee($pdo,$central,$companyId,$employeeRef);
    $result = FinanceEmployeeClientReceiptService::create($pdo,$_POST,[
        'id'=>(int)($_SESSION['user_id'] ?? 0),'role'=>(string)($_SESSION['role_code'] ?? 'company_owner'),
        'user_id'=>(int)($_SESSION['user_id'] ?? 0),'role_code'=>(string)($_SESSION['role_code'] ?? 'company_owner'),
    ],$employee);
    $_SESSION['employee_payments_success'] = 'Получено от клиента '.$result['client_name'].' — '.FinanceEmployeePaymentService::formatMoney($result['amount']).' ₽.';
} catch (Throwable $e) {
    $_SESSION['employee_payments_error'] = $e->getMessage();
}
$url='/company/finance/employee-payments';
if($employeeRef!=='')$url.='?employee_ref='.rawurlencode($employeeRef);
redirect_to($url);
