<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FinanceAllocationService;
use App\Service\FinanceDdsCategoryService;
use App\Service\FinanceEmployeePaymentService;
use App\Service\FinanceEmployeePersonalExpenseEventService;
use App\Service\FinanceMatchingRuleService;
use App\Service\FinanceStructureService;
use PDO;

final class FinanceEmployeePersonalExpenseController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {}

    public function createForm(): void
    {
        try {
            [$company, $pdo, $central] = $this->tenant();
            $employees = FinanceEmployeePaymentService::fetchActiveEmployees($pdo, $central, (int)$company['id']);
            $selectedRef = trim((string)($_GET['employee_ref'] ?? ''));
            $employee = $selectedRef !== ''
                ? FinanceEmployeePaymentService::resolveActiveEmployee($pdo, $central, (int)$company['id'], $selectedRef)
                : null;
            $event = null;
            $this->loadFormOptions($pdo, $cashFlowCenters, $expenseDdsCategories, $allowedExpenseDdsMap, $routes);
            require base_path('app/View/partials/company_finance_employee_personal_expense_form.php');
        } catch (\Throwable $e) {
            http_response_code(422);
            echo '<div class="form-alert alert-error">' . e($e->getMessage()) . '</div>';
        }
    }

    public function createSubmit(): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [$company, $pdo, $central] = $this->tenant();
            $employee = FinanceEmployeePaymentService::resolveActiveEmployee($pdo, $central, (int)$company['id'], $employeeRef);
            $event = FinanceEmployeePersonalExpenseEventService::create($pdo, $_POST, $this->user(), $employee);
            $_SESSION['employee_payments_success'] = 'Расход компании, оплаченный сотрудником, сохранён.';
            $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef((string)$event['employee_identity_type'], (int)$event['employee_identity_id']);
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirect($employeeRef);
    }

    public function editForm(string $id): void
    {
        try {
            [, $pdo, $central] = $this->tenant();
            $event = FinanceEmployeePersonalExpenseEventService::fetchOne($pdo, (int)$id);
            if (!$event || empty($event['event_group_id'])) throw new \InvalidArgumentException('Связанная операция не найдена.');
            $employees = [];
            $employee = [
                'ref' => FinanceEmployeePaymentService::makeEmployeeRef((string)$event['employee_identity_type'], (int)$event['employee_identity_id']),
                'identity_type' => (string)$event['employee_identity_type'],
                'identity_id' => (int)$event['employee_identity_id'],
                'full_name' => (string)$event['employee_name_snapshot'],
                'role_code' => (string)$event['employee_role_snapshot'],
            ];
            $this->loadFormOptions($pdo, $cashFlowCenters, $expenseDdsCategories, $allowedExpenseDdsMap, $routes);
            require base_path('app/View/partials/company_finance_employee_personal_expense_form.php');
        } catch (\Throwable $e) {
            http_response_code(422);
            echo '<div class="form-alert alert-error">' . e($e->getMessage()) . '</div>';
        }
    }

    public function updateSubmit(string $id): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [, $pdo] = $this->tenant();
            $event = FinanceEmployeePersonalExpenseEventService::update($pdo, (int)$id, $_POST, $this->user());
            $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef((string)$event['employee_identity_type'], (int)$event['employee_identity_id']);
            $_SESSION['employee_payments_success'] = 'Операция изменена. Взаиморасчёты сотрудника и расход компании синхронизированы.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirect($employeeRef);
    }

    public function cancelSubmit(string $id): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [, $pdo] = $this->tenant();
            $event = FinanceEmployeePersonalExpenseEventService::cancel($pdo, (int)$id, $this->user(), (string)($_POST['reason'] ?? ''));
            $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef((string)$event['employee_identity_type'], (int)$event['employee_identity_id']);
            $_SESSION['employee_payments_success'] = 'Операция отменена. Влияние на взаиморасчёты сотрудника и расходы компании снято.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirect($employeeRef);
    }

    public function listForEmployee(): void
    {
        try {
            [, $pdo] = $this->tenant();
            $employeeRef = trim((string)($_GET['employee_ref'] ?? ''));
            [$identityType, $identityId] = $this->parseEmployeeRef($employeeRef);
            $stmt = $pdo->prepare("SELECT pe.*,
                       COALESCE(cfu.name, pe.cash_flow_center_name_snapshot) AS cfu_name,
                       COALESCE(dds.name, pe.dds_category_name_snapshot) AS dds_name
                  FROM finance_employee_personal_expenses pe
             LEFT JOIN finance_cash_flow_centers cfu ON cfu.id=pe.cash_flow_center_id
             LEFT JOIN finance_dds_categories dds ON dds.id=pe.dds_category_id
                 WHERE pe.employee_identity_type=? AND pe.employee_identity_id=?
                   AND pe.event_group_id IS NOT NULL
              ORDER BY pe.operation_date DESC, pe.id DESC
                 LIMIT 100");
            $stmt->execute([$identityType, $identityId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            require base_path('app/View/partials/company_finance_employee_personal_expense_list.php');
        } catch (\Throwable $e) {
            http_response_code(422);
            echo '<div class="form-alert alert-error">' . e($e->getMessage()) . '</div>';
        }
    }

    private function loadFormOptions(PDO $pdo, &$cashFlowCenters, &$expenseDdsCategories, &$allowedExpenseDdsMap, &$routes): void
    {
        $cashFlowCenters = FinanceMatchingRuleService::fetchCashFlowCenters($pdo, true);
        $expenseDdsCategories = FinanceDdsCategoryService::fetchActiveForDirection($pdo, 'EXPENSE');
        $allowedExpenseDdsMap = FinanceStructureService::fetchAllowedMap($pdo, 'EXPENSE');
        $routes = FinanceAllocationService::fetchRoutesForAllocation($pdo, [
            'role_code' => (string)($_SESSION['role_code'] ?? ''),
            'user_id' => (int)($_SESSION['user_id'] ?? 0),
        ]);
    }

    private function parseEmployeeRef(string $ref): array
    {
        if (!preg_match('/^(TENANT_USER|COMPANY_USER|MEMBERSHIP):(\d+)$/D', trim($ref), $m) || (int)$m[2] <= 0) {
            throw new \InvalidArgumentException('Выберите сотрудника.');
        }
        return [$m[1], (int)$m[2]];
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
        if ($employeeRef !== '') $url .= '?employee_ref=' . rawurlencode($employeeRef);
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
