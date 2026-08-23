<?php

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Direct BANK <-> EMPLOYEE settlement.
 *
 * The imported bank finance operation remains the canonical bank leg and is
 * converted to TRANSFER. One counterpart TRANSFER is created on the employee
 * money account. No CASH operation or finance_cash_resolutions row is created.
 */
final class FinanceEmployeeBankSettlementService
{
    public static function settleBankTransaction(
        PDO $pdo,
        int $bankTransactionId,
        array $employee,
        array $user,
        ?int $ruleId = null,
        ?int $cashFlowCenterId = null,
        ?int $ddsCategoryId = null
    ): array {
        if ($bankTransactionId <= 0) {
            throw new \InvalidArgumentException('Банковская операция не найдена.');
        }
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT bt.*, fo.id AS finance_operation_id, fo.operation_type, fo.status AS finance_operation_status,
                        fo.source, fo.money_account_id, fo.operation_date, fo.amount, fo.currency, fo.purpose,
                        fo.dds_category_id, fo.cash_flow_center_id
                   FROM bank_transactions bt
                   JOIN finance_operations fo ON fo.bank_transaction_id = bt.id
                  WHERE bt.id=? FOR UPDATE"
            );
            $stmt->execute([$bankTransactionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Банковская операция не найдена или не связана с финансовой операцией.');
            if (($row['finance_operation_status'] ?? '') !== 'POSTED') throw new RuntimeException('Можно связать только проведённую банковскую операцию.');

            $existing = $pdo->prepare('SELECT id,finance_operation_id,movement_type FROM finance_employee_movements WHERE bank_transaction_id=? LIMIT 1');
            $existing->execute([$bankTransactionId]);
            $movement = $existing->fetch(PDO::FETCH_ASSOC);
            if ($movement) {
                if ($started) $pdo->commit();
                return [
                    'handled' => true,
                    'idempotent' => true,
                    'employee_movement_id' => (int)$movement['id'],
                    'employee_operation_id' => (int)$movement['finance_operation_id'],
                    'bank_transaction_id' => $bankTransactionId,
                ];
            }
            if (!empty($row['is_internal_transfer'])) {
                throw new RuntimeException('Банковская операция уже оформлена как внутренний перевод.');
            }

            $originalType = strtoupper((string)$row['operation_type']);
            if (!in_array($originalType, ['INCOME', 'EXPENSE'], true)) {
                throw new RuntimeException('Взаиморасчёт с сотрудником можно создать только из банковского поступления или списания.');
            }
            $movementType = $originalType === 'EXPENSE' ? 'PAYMENT' : 'RETURN';
            $bankDirection = $movementType === 'PAYMENT' ? 'out' : 'in';
            $employeeDirection = $movementType === 'PAYMENT' ? 'in' : 'out';
            $bankAccountId = (int)$row['money_account_id'];
            $employeeAccountId = FinanceEmployeeMoneyAccountService::accountId($pdo, $employee, $user);
            $group = 'TRF_EMP_DIRECT_' . substr(hash('sha256', 'employee_bank_direct:' . $bankTransactionId), 0, 24);
            $dedupe = hash('sha256', 'employee_bank_direct_counterpart:' . $bankTransactionId);
            $purpose = trim((string)($row['purpose'] ?? ''));
            $amount = (string)$row['amount'];
            $currency = (string)($row['currency'] ?? 'RUR');
            $date = (string)$row['operation_date'];
            $cfu = $cashFlowCenterId ?? (!empty($row['cash_flow_center_id']) ? (int)$row['cash_flow_center_id'] : null);
            $dds = $ddsCategoryId ?? (!empty($row['dds_category_id']) ? (int)$row['dds_category_id'] : null);
            $uid = (int)($user['id'] ?? $user['user_id'] ?? 0);
            $role = (string)($user['role'] ?? $user['role_code'] ?? ($ruleId !== null ? 'system' : 'company_owner'));
            $isManual = $ruleId === null;
            $classificationStatus = $isManual ? 'MANUAL' : 'AUTO';
            $classificationLocked = $isManual ? 1 : 0;

            $insertCounterpart = $pdo->prepare(
                "INSERT INTO finance_operations
                    (operation_type,status,source,money_account_id,transfer_account_id,transfer_group_id,transfer_direction,
                     operation_date,amount,currency,dds_category_id,cash_flow_center_id,purpose,comment,dedupe_hash,
                     classification_status,classification_rule_id,classification_locked,classification_updated_at,
                     created_by_user_id,created_by_role,posted_by_user_id,posted_by_role,posted_at)
                 VALUES
                    ('TRANSFER','POSTED','TRANSFER',?,?,?,?,?,?,?, ?,?,?,?, ?,?, ?,?,NOW(), ?,?,?,?,NOW())"
            );
            $insertCounterpart->execute([
                $employeeAccountId,
                $bankAccountId,
                $group,
                $employeeDirection,
                $date,
                $amount,
                $currency,
                $dds,
                $cfu,
                $purpose,
                'Прямой взаиморасчёт банк ↔ сотрудник' . ($ruleId !== null ? ' по правилу #' . $ruleId : ''),
                $dedupe,
                $classificationStatus,
                $ruleId,
                $classificationLocked,
                $uid > 0 ? $uid : 0,
                $role,
                $uid > 0 ? $uid : 0,
                $role,
            ]);
            $employeeOperationId = (int)$pdo->lastInsertId();

            $updateBankOperation = $pdo->prepare(
                "UPDATE finance_operations
                    SET operation_type='TRANSFER', source='TRANSFER', transfer_account_id=?, transfer_group_id=?, transfer_direction=?,
                        dds_category_id=?, cash_flow_center_id=?, classification_status=?, classification_rule_id=?,
                        classification_locked=?, classification_updated_at=NOW()
                  WHERE id=? AND status='POSTED'"
            );
            $updateBankOperation->execute([
                $employeeAccountId,
                $group,
                $bankDirection,
                $dds,
                $cfu,
                $classificationStatus,
                $ruleId,
                $classificationLocked,
                (int)$row['finance_operation_id'],
            ]);
            if ($updateBankOperation->rowCount() !== 1) {
                throw new RuntimeException('Не удалось преобразовать банковскую операцию во внутренний перевод сотруднику.');
            }

            $updateBank = $pdo->prepare(
                "UPDATE bank_transactions
                    SET is_internal_transfer=1, linked_cash_transaction_id=?, dds_category_id=?, cash_flow_center_id=?,
                        classification_status=?, classification_rule_id=?, classification_locked=?, classification_updated_at=NOW()
                  WHERE id=?"
            );
            $updateBank->execute([
                $employeeOperationId,
                $dds,
                $cfu,
                $classificationStatus,
                $ruleId,
                $classificationLocked,
                $bankTransactionId,
            ]);

            $employeeUserId = (string)$employee['identity_type'] === FinanceEmployeePaymentService::IDENTITY_TENANT_USER
                ? (int)$employee['identity_id'] : null;
            $insertMovement = $pdo->prepare(
                "INSERT INTO finance_employee_movements
                    (employee_user_id,employee_identity_type,employee_identity_id,employee_name_snapshot,employee_role_snapshot,
                     movement_type,source_type,finance_operation_id,bank_transaction_id,note,created_by_user_id,created_by_role)
                 VALUES (?,?,?,?,?,?, 'BANK',?,?,?, ?,?)"
            );
            $insertMovement->execute([
                $employeeUserId,
                (string)$employee['identity_type'],
                (int)$employee['identity_id'],
                (string)$employee['full_name'],
                (string)($employee['role_code'] ?? ''),
                $movementType,
                $employeeOperationId,
                $bankTransactionId,
                ($movementType === 'PAYMENT' ? 'Получено сотрудником из банка' : 'Возвращено сотрудником в банк')
                    . ($ruleId !== null ? ' по правилу #' . $ruleId : ''),
                $uid > 0 ? $uid : 0,
                $role,
            ]);
            $movementId = (int)$pdo->lastInsertId();

            FinanceAuditLogService::log(
                $pdo,
                'finance_employee_movement',
                $movementId,
                $ruleId !== null ? 'create_rule_direct_settlement' : 'create_bank_direct_settlement',
                null,
                [
                    'employee_identity_type' => (string)$employee['identity_type'],
                    'employee_identity_id' => (int)$employee['identity_id'],
                    'movement_type' => $movementType,
                    'bank_transaction_id' => $bankTransactionId,
                    'bank_finance_operation_id' => (int)$row['finance_operation_id'],
                    'employee_finance_operation_id' => $employeeOperationId,
                    'rule_id' => $ruleId,
                    'classification_status' => $classificationStatus,
                    'classification_locked' => $classificationLocked,
                    'cash_account_used' => false,
                ],
                $uid,
                $role
            );

            if ($started) $pdo->commit();
            return [
                'handled' => true,
                'rule_id' => $ruleId,
                'status' => $classificationStatus,
                'classification_locked' => $classificationLocked,
                'employee_movement_id' => $movementId,
                'employee_operation_id' => $employeeOperationId,
                'bank_operation_id' => (int)$row['finance_operation_id'],
                'bank_transaction_id' => $bankTransactionId,
                'transfer_group_id' => $group,
                'movement_type' => $movementType,
                'cash_account_used' => false,
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
