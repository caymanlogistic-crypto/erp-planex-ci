<?php

use App\Core\Database;
use App\Service\FinanceEmployeeDirectTransferService;
use App\Service\FinanceEmployeePaymentService;

verifyCsrfRequest();

$redirectRef = trim((string)($_SESSION['employee_payments_filter_employee_ref'] ?? $_POST['source_employee_ref'] ?? ''));
try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Компания не найдена.');
    }
    $central = $db->connection();
    $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new RuntimeException('Компания не найдена или неактивна.');
    }
    $pdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    $result = FinanceEmployeeDirectTransferService::transfer(
        $pdo,
        $central,
        $companyId,
        $_POST,
        ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role_code'] ?? 'company_owner']
    );
    if ($redirectRef === '') {
        $redirectRef = (string)$result['source_employee']['ref'];
    }
    $_SESSION['employee_payments_success'] = 'Передано '
        . FinanceEmployeePaymentService::formatMoney($result['amount'])
        . ' ₽: '
        . $result['source_employee']['full_name']
        . ' → '
        . $result['target_employee']['full_name']
        . '.';
} catch (Throwable $e) {
    $_SESSION['employee_payments_error'] = $e->getMessage();
}

$url = '/company/finance/employee-payments';
if ($redirectRef !== '') {
    $url .= '?employee_ref=' . rawurlencode($redirectRef);
}
redirect_to($url);
