<?php

namespace App\Service;

use PDO;

final class FinanceEmployeeCompanyFundedExpenseService
{
    public const SOURCE_TYPE = 'COMPANY_FUNDED';

    public static function fetchCandidates(PDO $pdo, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $pdo->prepare("SELECT bt.id, bt.operation_date, bt.document_number, bt.counterparty_name, bt.counterparty_inn,
                                     bt.debit_amount, bt.credit_amount, bt.purpose, ba.account_number,
                                     fo.id AS finance_operation_id, fo.amount
                                FROM bank_transactions bt
                                JOIN bank_accounts ba ON ba.id = bt.account_id
                                JOIN finance_operations fo ON fo.bank_transaction_id = bt.id
                                 AND fo.status='POSTED' AND fo.operation_type='EXPENSE' AND fo.source='BANK_STATEMENT'
                               WHERE bt.debit_amount > 0
                                 AND COALESCE(bt.credit_amount,0) = 0
                                 AND COALESCE(bt.is_internal_transfer,0)=0
                                 AND NOT EXISTS (
                                     SELECT 1 FROM finance_employee_movements fem
                                      WHERE fem.finance_operation_id = fo.id OR fem.bank_transaction_id = bt.id
                                 )
                            ORDER BY bt.operation_date DESC, bt.id DESC
                               LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function mark(PDO $pdo, int $bankTransactionId, array $employee, array $user, ?string $comment = null): array
    {
        if ($bankTransactionId <= 0) throw new \InvalidArgumentException('Выберите банковское списание.');
        self::assertEmployee($employee);
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT bt.*, fo.id AS finance_operation_id, fo.operation_type AS finance_operation_type,
                                          fo.status AS finance_operation_status, fo.source AS finance_operation_source, fo.amount AS finance_operation_amount
                                     FROM bank_transactions bt
                                     JOIN finance_operations fo ON fo.bank_transaction_id=bt.id
                                    WHERE bt.id=? FOR UPDATE");
            $stmt->execute([$bankTransactionId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) throw new \RuntimeException('Банковская операция не найдена или не связана с финансовой операцией.');
            if (($tx['finance_operation_status'] ?? '') !== 'POSTED'
                || ($tx['finance_operation_type'] ?? '') !== 'EXPENSE'
                || ($tx['finance_operation_source'] ?? '') !== 'BANK_STATEMENT') {
                throw new \RuntimeException('Можно отметить только обычное проведённое банковское списание компании.');
            }
            if (!empty($tx['is_internal_transfer'])) throw new \RuntimeException('Внутренний перевод нельзя оформить как расход сотрудника, оплаченный компанией.');
            $debit = self::cents((string)($tx['debit_amount'] ?? '0'));
            $credit = self::cents((string)($tx['credit_amount'] ?? '0'));
            $operationAmount = self::cents((string)($tx['finance_operation_amount'] ?? '0'));
            if ($debit <= 0 || $credit !== 0 || $operationAmount !== $debit) {
                throw new \RuntimeException('Сумма банковского списания не соответствует финансовой операции.');
            }
            $check = $pdo->prepare('SELECT id FROM finance_employee_movements WHERE finance_operation_id=? OR bank_transaction_id=? LIMIT 1 FOR UPDATE');
            $check->execute([(int)$tx['finance_operation_id'], $bankTransactionId]);
            if ($check->fetchColumn()) throw new \RuntimeException('Эта банковская операция уже отражена во взаиморасчётах с сотрудником.');

            $uid = (int)($user['id'] ?? $user['user_id'] ?? 0);
            $role = trim((string)($user['role'] ?? $user['role_code'] ?? 'company_owner')) ?: 'company_owner';
            if ($uid <= 0) throw new \RuntimeException('Не определён пользователь, выполняющий операцию.');
            $counterparty = trim((string)($tx['counterparty_name'] ?? ''));
            $purpose = trim((string)($tx['purpose'] ?? ''));
            $note = 'Расход сотрудника, оплаченный компанией';
            if ($counterparty !== '') $note .= ': ' . $counterparty;
            if ($purpose !== '') $note .= ' · ' . $purpose;
            $comment = trim((string)$comment);
            if ($comment !== '') $note .= ' · ' . $comment;
            if (mb_strlen($note) > 1000) $note = mb_substr($note, 0, 1000);

            $localUserId = ($employee['identity_type'] ?? '') === FinanceEmployeePaymentService::IDENTITY_TENANT_USER
                ? (int)$employee['identity_id'] : null;
            $insert = $pdo->prepare("INSERT INTO finance_employee_movements
                (employee_user_id,employee_identity_type,employee_identity_id,employee_name_snapshot,employee_role_snapshot,
                 movement_type,source_type,finance_operation_id,bank_transaction_id,note,created_by_user_id,created_by_role)
                VALUES (?,?,?,?,?,'PAYMENT',?, ?,NULL,?,?,?)");
            $insert->execute([
                $localUserId,(string)$employee['identity_type'],(int)$employee['identity_id'],
                (string)$employee['full_name'],(string)($employee['role_code'] ?? ''),
                self::SOURCE_TYPE,(int)$tx['finance_operation_id'],$note,$uid,$role,
            ]);
            $movementId = (int)$pdo->lastInsertId();
            FinanceAuditLogService::log($pdo,'finance_employee_movement',$movementId,'company_funded_expense_mark',null,[
                'employee_ref'=>(string)$employee['ref'],
                'bank_transaction_id'=>$bankTransactionId,
                'finance_operation_id'=>(int)$tx['finance_operation_id'],
                'amount'=>number_format($debit / 100, 2, '.', ''),
                'counterparty_name'=>$counterparty,
                'closing_documents_preserved'=>true,
            ],$uid,$role);
            if ($started) $pdo->commit();
            return ['movement_id'=>$movementId,'finance_operation_id'=>(int)$tx['finance_operation_id'],'bank_transaction_id'=>$bankTransactionId,'amount'=>number_format($debit / 100, 2, '.', ''),'counterparty_name'=>$counterparty];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function unmark(PDO $pdo, int $movementId, array $user): array
    {
        if ($movementId <= 0) throw new \InvalidArgumentException('Операция сотрудника не найдена.');
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT fem.*, fo.bank_transaction_id AS source_bank_transaction_id, fo.amount
                                     FROM finance_employee_movements fem
                                     JOIN finance_operations fo ON fo.id=fem.finance_operation_id
                                    WHERE fem.id=? FOR UPDATE");
            $stmt->execute([$movementId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($row['source_type'] ?? '') !== self::SOURCE_TYPE || !empty($row['bank_transaction_id'])) {
                throw new \RuntimeException('Эта операция не является расходом сотрудника, оплаченным компанией.');
            }
            $uid=(int)($user['id'] ?? $user['user_id'] ?? 0);
            $role=trim((string)($user['role'] ?? $user['role_code'] ?? 'company_owner')) ?: 'company_owner';
            if ($uid <= 0) throw new \RuntimeException('Не определён пользователь, выполняющий операцию.');
            $pdo->prepare('DELETE FROM finance_employee_movements WHERE id=?')->execute([$movementId]);
            FinanceAuditLogService::log($pdo,'finance_employee_movement',$movementId,'company_funded_expense_unmark',[
                'employee_identity_type'=>(string)$row['employee_identity_type'],
                'employee_identity_id'=>(int)$row['employee_identity_id'],
                'bank_transaction_id'=>(int)$row['source_bank_transaction_id'],
                'finance_operation_id'=>(int)$row['finance_operation_id'],
                'amount'=>(string)$row['amount'],
            ],['removed'=>true,'money_operation_unchanged'=>true],$uid,$role);
            if ($started) $pdo->commit();
            return ['bank_transaction_id'=>(int)$row['source_bank_transaction_id'],'finance_operation_id'=>(int)$row['finance_operation_id']];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function assertEmployee(array $employee): void
    {
        if (empty($employee['ref']) || trim((string)($employee['full_name'] ?? '')) === ''
            || !in_array(($employee['identity_type'] ?? ''), [FinanceEmployeePaymentService::IDENTITY_TENANT_USER,FinanceEmployeePaymentService::IDENTITY_COMPANY_USER,FinanceEmployeePaymentService::IDENTITY_MEMBERSHIP], true)
            || (int)($employee['identity_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Сотрудник не найден или его аккаунт неактивен.');
        }
    }

    private static function cents(string $value): int
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));
        if ($value === '' || !preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value)) return 0;
        $negative = str_starts_with($value, '-');
        if ($negative) $value = substr($value, 1);
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int)$whole * 100) + (int)str_pad(substr($fraction,0,2),2,'0');
        return $negative ? -$cents : $cents;
    }
}
