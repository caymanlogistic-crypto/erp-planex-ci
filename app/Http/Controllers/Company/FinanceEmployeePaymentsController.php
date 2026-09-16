<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FinanceCashInvoiceEventService;
use App\Service\FinanceCashService;
use App\Service\FinanceEmployeeInvoicePaymentEventService;
use App\Service\FinanceEmployeePaymentService;
use App\Service\FinanceEmployeeCompanyFundedExpenseService;
use App\Service\FinanceEmployeePersonalExpenseEventService;
use App\Service\FinanceEmployeeTransferService;
use PDO;

final class FinanceEmployeePaymentsController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {}

    public function index(): void
    {
        [$company, $pdo, $central] = $this->tenant();
        $employees = FinanceEmployeePaymentService::fetchActiveEmployees($pdo, $central, (int)$company['id']);
        $summaries = FinanceEmployeePaymentService::fetchEmployeeSummaries($pdo);
        $selectedRef = trim((string)($_GET['employee_ref'] ?? ''));
        if ($selectedRef === '') {
            $selectedRef = (string)($summaries[0]['employee_ref'] ?? ($employees[0]['ref'] ?? ''));
        }
        $_SESSION['employee_payments_filter_employee_ref'] = $selectedRef;

        $ledger = [];
        $selectedEmployee = null;
        $employeeInvoicePayments = [];
        $employeePersonalExpenses = [];
        $carrierInvoices = [];
        $companyFundedCandidates = [];
        if ($selectedRef !== '') {
            try {
                $selectedEmployee = FinanceEmployeePaymentService::resolveActiveEmployee($pdo, $central, (int)$company['id'], $selectedRef);
            } catch (\Throwable $e) {
                $ledger = FinanceEmployeePaymentService::fetchEmployeeLedger($pdo, $selectedRef);
                if ($ledger) {
                    $selectedEmployee = [
                        'ref' => $selectedRef,
                        'full_name' => (string)$ledger[0]['full_name'],
                        'status' => 'inactive',
                    ];
                }
            }
            if ($selectedEmployee) {
                $ledger = $ledger ?: FinanceEmployeePaymentService::fetchEmployeeLedger($pdo, $selectedRef);
                $ledger = FinanceEmployeeTransferService::decorateLedger($pdo, $ledger);
                $employeeInvoicePayments = FinanceEmployeeInvoicePaymentEventService::fetchForEmployee($pdo, $selectedRef);
                $employeePersonalExpenses = FinanceEmployeePersonalExpenseEventService::fetchForEmployee($pdo, $selectedRef);
                $carrierInvoices = FinanceCashInvoiceEventService::fetchCarrierInvoices($pdo);
                $companyFundedCandidates = FinanceEmployeeCompanyFundedExpenseService::fetchCandidates($pdo);
            }
        }

        $pageTitle = 'Выплаты сотрудникам';
        $pageContext = 'Финансы › Выплаты сотрудникам';
        $topbarCrumbs = [
            ['label' => mb_strtoupper((string)($company['name'] ?? 'Компания')), 'url' => app_url('/company/dashboard')],
            ['label' => 'Финансы', 'url' => app_url('/company/finance/dashboard')],
            ['label' => 'Выплаты сотрудникам', 'url' => null],
        ];
        $successFlash = $_SESSION['employee_payments_success'] ?? null;
        $errorFlash = $_SESSION['employee_payments_error'] ?? null;
        unset($_SESSION['employee_payments_success'], $_SESSION['employee_payments_error']);

        ob_start();
        require base_path('app/View/pages/company_finance_employee_payments.php');
        require base_path('app/View/partials/company_finance_employee_invoice_tools.php');
        require base_path('app/View/partials/company_finance_employee_company_funded_expense.php');
        require base_path('app/View/partials/company_finance_employee_unified_ledger_ui.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function createForm(): void
    {
        [$company, $pdo, $central] = $this->tenant();
        $movementType = strtoupper(trim((string)($_GET['type'] ?? 'PAYMENT')));
        if (!in_array($movementType, ['PAYMENT', 'RETURN'], true)) {
            $movementType = 'PAYMENT';
        }
        $employees = FinanceEmployeePaymentService::fetchActiveEmployees($pdo, $central, (int)$company['id']);
        $cashAccounts = FinanceCashService::fetchMoneyAccounts($pdo, 'CASH', true);
        $bankCandidates = [];
        require base_path('app/View/partials/company_finance_employee_payment_form.php');
    }

    public function createSubmit(): void
    {
        verifyCsrfRequest();
        try {
            [$company, $pdo, $central] = $this->tenant();
            $employee = FinanceEmployeePaymentService::resolveActiveEmployee(
                $pdo,
                $central,
                (int)$company['id'],
                trim((string)($_POST['employee_ref'] ?? ''))
            );
            $sourceType = strtoupper(trim((string)($_POST['source_type'] ?? '')));
            if ($sourceType !== 'CASH') {
                throw new \InvalidArgumentException('Прямая выплата сотруднику с расчётного счёта отключена. Используйте правило разнесения: банк ↔ касса ↔ сотрудник.');
            }
            FinanceEmployeePaymentService::createCashMovement(
                $pdo,
                $_POST,
                ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role_code'] ?? 'company_owner'],
                $employee
            );
            $_SESSION['employee_payments_success'] = 'Кассовая операция сотрудника сохранена.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        redirect_to('/company/finance/employee-payments');
    }

    public function transferSubmit(): void
    {
        verifyCsrfRequest();
        $redirectRef = trim((string)($_SESSION['employee_payments_filter_employee_ref'] ?? $_POST['source_employee_ref'] ?? ''));
        try {
            [$company, $pdo, $central] = $this->tenant();
            $result = FinanceEmployeeTransferService::transfer(
                $pdo,
                $central,
                (int)$company['id'],
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
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirectToEmployeePayments($redirectRef);
    }

    public function transferUpdateSubmit(): void
    {
        verifyCsrfRequest();
        $redirectRef = trim((string)($_POST['return_employee_ref'] ?? $_POST['source_employee_ref'] ?? ''));
        try {
            [$company, $pdo, $central] = $this->tenant();
            $result = FinanceEmployeeTransferService::updateTransfer(
                $pdo,
                $central,
                (int)$company['id'],
                trim((string)($_POST['transfer_group_id'] ?? '')),
                $_POST,
                ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role_code'] ?? 'company_owner']
            );
            $_SESSION['employee_payments_success'] = 'Передача денег сотруднику сохранена.';
            if ($redirectRef === '') {
                $redirectRef = (string)$result['source_employee']['ref'];
            }
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirectToEmployeePayments($redirectRef);
    }

    public function transferDeleteSubmit(): void
    {
        verifyCsrfRequest();
        $redirectRef = trim((string)($_POST['return_employee_ref'] ?? ''));
        try {
            [, $pdo] = $this->tenant();
            $result = FinanceEmployeeTransferService::deleteTransfer(
                $pdo,
                trim((string)($_POST['transfer_group_id'] ?? '')),
                ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role_code'] ?? 'company_owner']
            );
            $_SESSION['employee_payments_success'] = 'Передача денег между сотрудниками удалена.';
            if ($redirectRef === '') {
                $redirectRef = (string)$result['source_employee_ref'];
            }
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirectToEmployeePayments($redirectRef);
    }

    public function employeeDetail(string $type, string $id): void
    {
        [$company, $pdo, $central] = $this->tenant();
        $employeeRef = FinanceEmployeePaymentService::makeEmployeeRef(strtoupper($type), (int)$id);
        try {
            $employee = FinanceEmployeePaymentService::resolveActiveEmployee($pdo, $central, (int)$company['id'], $employeeRef);
        } catch (\Throwable $e) {
            $ledger = FinanceEmployeePaymentService::fetchEmployeeLedger($pdo, $employeeRef);
            if (!$ledger) {
                http_response_code(404);
                echo '<div class="form-alert alert-error">Сотрудник не найден.</div>';
                return;
            }
            $employee = [
                'ref' => $employeeRef,
                'full_name' => (string)$ledger[0]['full_name'],
                'status' => 'inactive',
            ];
        }
        $ledger = $ledger ?? FinanceEmployeePaymentService::fetchEmployeeLedger($pdo, $employeeRef);
        $employees = FinanceEmployeePaymentService::fetchActiveEmployees($pdo, $central, (int)$company['id']);
        require base_path('app/View/partials/company_finance_employee_payment_ledger.php');
    }

    public function reassignMovement(int $id): void
    {
        verifyCsrfRequest();
        try {
            [$company, $pdo, $central] = $this->tenant();
            $employee = FinanceEmployeePaymentService::resolveActiveEmployee(
                $pdo,
                $central,
                (int)$company['id'],
                trim((string)($_POST['employee_ref'] ?? ''))
            );
            FinanceEmployeePaymentService::reassignMovement(
                $pdo,
                $id,
                $employee,
                ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role_code'] ?? 'company_owner']
            );
            $_SESSION['employee_payments_success'] = 'Сотрудник для операции изменён.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        redirect_to('/company/finance/employee-payments?employee_ref=' . rawurlencode((string)($_POST['employee_ref'] ?? '')));
    }

    public function bankLink(): void
    {
        verifyCsrfRequest();
        $_SESSION['bank_finance_error'] = 'Прямая связь банковской операции с сотрудником отключена. Создайте правило взаиморасчёта с сотрудником — система проведёт деньги через кассу.';
        redirect_to('/company/finance/bank-accounts');
    }

    public function bankUnlink(): void
    {
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant();
            $bankTransactionId = (int)($_POST['bank_transaction_id'] ?? 0);
            if ($bankTransactionId <= 0) {
                throw new \InvalidArgumentException('Банковская операция не найдена.');
            }
            FinanceEmployeePaymentService::unlinkBankTransaction(
                $pdo,
                $bankTransactionId,
                ['id' => $_SESSION['user_id'] ?? 0, 'role' => $_SESSION['role_code'] ?? 'company_owner']
            );
            $_SESSION['bank_finance_success'] = 'Связь с сотрудником удалена.';
        } catch (\Throwable $e) {
            $_SESSION['bank_finance_error'] = $e->getMessage();
        }
        redirect_to('/company/finance/bank-accounts');
    }

    public function companyFundedCreate(): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [$company, $pdo, $central] = $this->tenant();
            $employee = FinanceEmployeePaymentService::resolveActiveEmployee($pdo, $central, (int)$company['id'], $employeeRef);
            $result = FinanceEmployeeCompanyFundedExpenseService::mark(
                $pdo,
                (int)($_POST['bank_transaction_id'] ?? 0),
                $employee,
                ['id'=>(int)($_SESSION['user_id']??0),'role'=>(string)($_SESSION['role_code']??'company_owner')],
                (string)($_POST['comment'] ?? '')
            );
            $_SESSION['employee_payments_success'] = 'Расход компании отражён за сотрудником: '
                . FinanceEmployeePaymentService::formatMoney($result['amount']) . ' ₽.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirectToEmployeePayments($employeeRef);
    }

    public function companyFundedUnmark(): void
    {
        verifyCsrfRequest();
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        try {
            [, $pdo] = $this->tenant();
            FinanceEmployeeCompanyFundedExpenseService::unmark(
                $pdo,
                (int)($_POST['movement_id'] ?? 0),
                ['id'=>(int)($_SESSION['user_id']??0),'role'=>(string)($_SESSION['role_code']??'company_owner')]
            );
            $_SESSION['employee_payments_success'] = 'Отметка «оплачено компанией за сотрудника» снята. Банковский платёж не изменён.';
        } catch (\Throwable $e) {
            $_SESSION['employee_payments_error'] = $e->getMessage();
        }
        $this->redirectToEmployeePayments($employeeRef);
    }

    private function redirectToEmployeePayments(string $employeeRef): void
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
        if ($companyId <= 0) {
            throw new \RuntimeException('Компания не найдена.');
        }
        $central = $this->db->connection();
        $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new \RuntimeException('Компания не найдена или неактивна.');
        }
        $pdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
        return [$company, $pdo, $central];
    }
}
