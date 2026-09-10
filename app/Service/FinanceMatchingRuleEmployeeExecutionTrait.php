<?php
namespace App\Service;

use PDO;

trait FinanceMatchingRuleEmployeeExecutionTrait
{
    /**
     * Backward-compatible action name, new direct execution semantics.
     * Existing rules keep action_type=employee_cash_settlement in storage, but
     * every NEW execution creates BANK <-> EMPLOYEE directly and never CASH.
     */
    private static function executeEmployeeCashSettlement(PDO $pdo, array $op, array $rule): array
    {
        $originalType = strtoupper((string)($op['operation_type'] ?? ''));
        if (!in_array($originalType, ['INCOME', 'EXPENSE'], true) || ($op['source'] ?? '') !== 'BANK_STATEMENT') {
            throw new \RuntimeException('Взаиморасчёт с сотрудником можно создать только из банковского поступления или списания.');
        }

        $bankTxId = (int)($op['bank_transaction_id'] ?? 0);
        $employeeType = (string)($rule['target_employee_identity_type'] ?? '');
        $employeeId = (int)($rule['target_employee_identity_id'] ?? 0);
        $employeeName = trim((string)($rule['target_employee_name_snapshot'] ?? ''));
        $employeeRole = trim((string)($rule['target_employee_role_snapshot'] ?? ''));
        $cfu = self::nullableInt($rule['target_cash_flow_center_id'] ?? null);
        $dds = self::nullableInt($rule['target_dds_category_id'] ?? null);

        if ($bankTxId <= 0 || $employeeId <= 0 || $employeeName === '' || $cfu === null || $dds === null) {
            throw new \RuntimeException('Правило взаиморасчёта с сотрудником заполнено не полностью.');
        }
        if (!in_array($employeeType, [FinanceEmployeePaymentService::IDENTITY_TENANT_USER, FinanceEmployeePaymentService::IDENTITY_COMPANY_USER, FinanceEmployeePaymentService::IDENTITY_MEMBERSHIP], true)) {
            throw new \RuntimeException('Некорректный тип сотрудника в правиле.');
        }

        $employee = [
            'identity_type' => $employeeType,
            'identity_id' => $employeeId,
            'full_name' => $employeeName,
            'role_code' => $employeeRole,
            'ref' => FinanceEmployeePaymentService::makeEmployeeRef($employeeType, $employeeId),
        ];

        return FinanceEmployeeBankSettlementService::settleBankTransaction(
            $pdo,
            $bankTxId,
            $employee,
            ['id' => 0, 'role' => 'system', 'user_id' => 0, 'role_code' => 'system'],
            (int)$rule['id'],
            $cfu,
            $dds
        );
    }
}
