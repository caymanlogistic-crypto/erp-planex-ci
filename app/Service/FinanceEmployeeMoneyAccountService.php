<?php

namespace App\Service;

use PDO;

/**
 * Direct money holder for an employee.
 *
 * New employee settlements must use EMPLOYEE money accounts instead of the
 * historical technical CASH account. Existing CASH rows remain immutable and
 * readable for audit/history.
 */
final class FinanceEmployeeMoneyAccountService
{
    public const ACCOUNT_TYPE = 'EMPLOYEE';

    public static function find(PDO $pdo, array $employee, bool $forUpdate = false): ?array
    {
        self::assertEmployee($employee);
        if (!self::mappingTableExists($pdo)) {
            return null;
        }

        $sql = "SELECT map.*, account.type AS account_type, account.name AS account_name,
                       account.currency, account.is_active AS account_is_active
                  FROM finance_employee_money_accounts map
                  JOIN finance_money_accounts account ON account.id = map.money_account_id
                 WHERE map.employee_identity_type = ?
                   AND map.employee_identity_id = ?
                 LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([(string)$employee['identity_type'], (int)$employee['identity_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function ensure(PDO $pdo, array $employee, array $user): array
    {
        self::assertEmployee($employee);
        if (!self::mappingTableExists($pdo)) {
            throw new \RuntimeException('Схема прямых взаиморасчётов с сотрудниками ещё не установлена.');
        }

        $existing = self::find($pdo, $employee, true);
        if ($existing) {
            if (($existing['account_type'] ?? '') !== self::ACCOUNT_TYPE) {
                throw new \RuntimeException('Счёт сотрудника имеет неверный тип.');
            }
            if ((int)($existing['is_active'] ?? 0) !== 1 || (int)($existing['account_is_active'] ?? 0) !== 1) {
                throw new \RuntimeException('Счёт сотрудника неактивен.');
            }
            return $existing;
        }

        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $name = 'Сотрудник · ' . trim((string)$employee['full_name']);
            $insertAccount = $pdo->prepare(
                "INSERT INTO finance_money_accounts
                    (type, name, bank_account_id, currency, opening_balance, opening_balance_date,
                     is_active, created_by_user_id, created_by_role, updated_by_user_id, updated_by_role)
                 VALUES (?, ?, NULL, 'RUR', 0.00, NULL, 1, ?, ?, ?, ?)"
            );
            $userId = (int)($user['id'] ?? 0);
            $userRole = trim((string)($user['role'] ?? 'company_owner'));
            $insertAccount->execute([
                self::ACCOUNT_TYPE,
                $name,
                $userId > 0 ? $userId : null,
                $userRole !== '' ? $userRole : null,
                $userId > 0 ? $userId : null,
                $userRole !== '' ? $userRole : null,
            ]);
            $accountId = (int)$pdo->lastInsertId();

            $insertMap = $pdo->prepare(
                "INSERT INTO finance_employee_money_accounts
                    (money_account_id, employee_identity_type, employee_identity_id,
                     employee_name_snapshot, employee_role_snapshot,
                     is_active, created_by_user_id, created_by_role)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
            );
            $insertMap->execute([
                $accountId,
                (string)$employee['identity_type'],
                (int)$employee['identity_id'],
                trim((string)$employee['full_name']),
                trim((string)($employee['role_code'] ?? '')) ?: null,
                $userId > 0 ? $userId : null,
                $userRole !== '' ? $userRole : null,
            ]);

            if ($started) {
                $pdo->commit();
            }

            $created = self::find($pdo, $employee, false);
            if (!$created) {
                throw new \RuntimeException('Не удалось создать счёт сотрудника.');
            }
            return $created;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function accountId(PDO $pdo, array $employee, array $user): int
    {
        $account = self::ensure($pdo, $employee, $user);
        $id = (int)($account['money_account_id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Счёт сотрудника не найден.');
        }
        return $id;
    }

    public static function mappingTableExists(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'finance_employee_money_accounts'");
        return (bool)$stmt->fetchColumn();
    }

    private static function assertEmployee(array $employee): void
    {
        $type = trim((string)($employee['identity_type'] ?? ''));
        $id = (int)($employee['identity_id'] ?? 0);
        $name = trim((string)($employee['full_name'] ?? ''));
        if (!in_array($type, [FinanceEmployeePaymentService::IDENTITY_TENANT_USER, FinanceEmployeePaymentService::IDENTITY_COMPANY_USER, FinanceEmployeePaymentService::IDENTITY_MEMBERSHIP], true)
            || $id <= 0
            || $name === '') {
            throw new \InvalidArgumentException('Некорректный сотрудник для финансового счёта.');
        }
    }
}
