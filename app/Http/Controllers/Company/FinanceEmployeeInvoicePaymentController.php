<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FinanceEmployeeInvoicePaymentEventService;
use App\Service\FinanceEmployeePaymentService;
use PDO;

final class FinanceEmployeeInvoicePaymentController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {}

    public function createSubmit(): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [$company, $pdo, $central] = $this->tenant();
            $employee = FinanceEmployeePaymentService::resolveActiveEmployee(
                $pdo,
                $central,
                (int)$company['id'],
                $employeeRef
            );
            $event = FinanceEmployeeInvoicePaymentEventService::create($pdo, $_POST, $this->user(), $employee);
            $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef(
                (string)$event['employee_identity_type'],
                (int)$event['employee_identity_id']
            );
            $_SESSION['employee_payments_success'] = 'Оплата счёта сотрудником проведена. Сальдо сотрудника уменьшено, входящий счёт закрыт на указанную сумму.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirect($employeeRef);
    }

    public function updateSubmit(): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [, $pdo] = $this->tenant();
            $eventId = (int)($_POST['event_id'] ?? 0);
            $event = FinanceEmployeeInvoicePaymentEventService::update($pdo, $eventId, $_POST, $this->user());
            $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef(
                (string)$event['employee_identity_type'],
                (int)$event['employee_identity_id']
            );
            $_SESSION['employee_payments_success'] = 'Оплата счёта изменена. Сотрудник, кассовые проводки и расчёты по счёту синхронизированы.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirect($employeeRef);
    }

    public function cancelSubmit(): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [, $pdo] = $this->tenant();
            $eventId = (int)($_POST['event_id'] ?? 0);
            $event = FinanceEmployeeInvoicePaymentEventService::cancel(
                $pdo,
                $eventId,
                $this->user(),
                (string)($_POST['reason'] ?? '')
            );
            $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef(
                (string)$event['employee_identity_type'],
                (int)$event['employee_identity_id']
            );
            $_SESSION['employee_payments_success'] = 'Оплата счёта отменена. Счёт и связанные обязательства пересчитаны, влияние на сальдо сотрудника снято.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirect($employeeRef);
    }

    private function user(): array
    {
        return [
            'id' => (int)($_SESSION['user_id'] ?? 0),
            'role' => (string)($_SESSION['role_code'] ?? 'company_owner'),
            'user_id' => (int)($_SESSION['user_id'] ?? 0),
            'role_code' => (string)($_SESSION['role_code'] ?? 'company_owner'),
        ];
    }

    private function redirect(string $employeeRef): void
    {
        $url = '/company/finance/employee-payments';
        if ($employeeRef !== '') {
            $url .= '?employee_ref=' . rawurlencode($employeeRef);
        }
        redirect_to($url);
    }

    private function tenant(): array
    {
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new \RuntimeException('Компания не найдена.');
        $central = $this->db->connection();
        $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new \RuntimeException('Компания не найдена или неактивна.');
        $pdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
        return [$company, $pdo, $central];
    }
}
