<?php

namespace App\Service;

use PDO;
use PDOException;

final class BankFinanceService
{
    public static function upsertAccount(PDO $localPdo, array $data): int
    {
        $stmt = $localPdo->prepare(
            'INSERT INTO `bank_accounts` (`account_number`, `bank_name`, `currency`, `company_inn`, `company_name`, `opening_balance`, `closing_balance`)
             VALUES (:account_number, :bank_name, :currency, :company_inn, :company_name, :opening_balance, :closing_balance)
             ON DUPLICATE KEY UPDATE
                `bank_name` = COALESCE(VALUES(`bank_name`), `bank_name`),
                `currency` = COALESCE(VALUES(`currency`), `currency`),
                `company_inn` = COALESCE(VALUES(`company_inn`), `company_inn`),
                `company_name` = COALESCE(VALUES(`company_name`), `company_name`),
                `opening_balance` = COALESCE(VALUES(`opening_balance`), `opening_balance`),
                `closing_balance` = COALESCE(VALUES(`closing_balance`), `closing_balance`)'
        );

        $stmt->execute([
            ':account_number' => $data['account_number'] ?? '',
            ':bank_name' => $data['bank_name'] ?? null,
            ':currency' => $data['currency'] ?? 'RUR',
            ':company_inn' => $data['company_inn'] ?? null,
            ':company_name' => $data['company_name'] ?? null,
            ':opening_balance' => $data['opening_balance'] ?? null,
            ':closing_balance' => $data['closing_balance'] ?? null,
        ]);

        $stmt = $localPdo->prepare('SELECT `id` FROM `bank_accounts` WHERE `account_number` = :account_number LIMIT 1');
        $stmt->execute([':account_number' => $data['account_number'] ?? '']);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : 0;
    }

    public static function importExists(PDO $localPdo, string $fileHash): bool
    {
        $stmt = $localPdo->prepare('SELECT COUNT(*) FROM `bank_statement_imports` WHERE `file_hash` = :hash');
        $stmt->execute([':hash' => $fileHash]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function createImportRecord(PDO $localPdo, array $data): int
    {
        $stmt = $localPdo->prepare(
            'INSERT INTO `bank_statement_imports`
                (`source`, `source_uid`, `filename`, `file_hash`, `account_number`, `period_from`, `period_to`, `imported_transactions`, `imported_balances`, `status`, `error_message`)
             VALUES
                (:source, :source_uid, :filename, :file_hash, :account_number, :period_from, :period_to, :imported_transactions, :imported_balances, :status, :error_message)'
        );

        $stmt->execute([
            ':source' => $data['source'] ?? 'manual',
            ':source_uid' => $data['source_uid'] ?? null,
            ':filename' => $data['filename'] ?? null,
            ':file_hash' => $data['file_hash'] ?? '',
            ':account_number' => $data['account_number'] ?? null,
            ':period_from' => $data['period_from'] ?? null,
            ':period_to' => $data['period_to'] ?? null,
            ':imported_transactions' => $data['imported_transactions'] ?? 0,
            ':imported_balances' => $data['imported_balances'] ?? 0,
            ':status' => $data['status'] ?? 'completed',
            ':error_message' => $data['error_message'] ?? null,
        ]);

        return (int)$localPdo->lastInsertId();
    }

    public static function insertTransaction(PDO $localPdo, array $tx, ?int &$insertedId = null): bool
    {
        try {
            $stmt = $localPdo->prepare(
                'INSERT INTO `bank_transactions`
                    (`account_id`, `operation_date`, `document_number`, `operation_type`, `counterparty_name`, `counterparty_inn`, `counterparty_bank_bik`, `counterparty_account`, `debit_amount`, `credit_amount`, `purpose`, `dedupe_hash`)
                 VALUES
                    (:account_id, :operation_date, :document_number, :operation_type, :counterparty_name, :counterparty_inn, :counterparty_bank_bik, :counterparty_account, :debit_amount, :credit_amount, :purpose, :dedupe_hash)'
            );

            $stmt->execute([
                ':account_id' => $tx['account_id'],
                ':operation_date' => $tx['operation_date'],
                ':document_number' => $tx['document_number'] ?? null,
                ':operation_type' => $tx['operation_type'] ?? null,
                ':counterparty_name' => $tx['counterparty_name'] ?? null,
                ':counterparty_inn' => $tx['counterparty_inn'] ?? null,
                ':counterparty_bank_bik' => $tx['counterparty_bank_bik'] ?? null,
                ':counterparty_account' => $tx['counterparty_account'] ?? null,
                ':debit_amount' => $tx['debit_amount'] ?? '0.00',
                ':credit_amount' => $tx['credit_amount'] ?? '0.00',
                ':purpose' => $tx['purpose'] ?? null,
                ':dedupe_hash' => $tx['dedupe_hash'] ?? '',
            ]);

            if ($stmt->rowCount() > 0) {
                $insertedId = (int)$localPdo->lastInsertId();
                return true;
            }
            return false;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $insertedId = null;
                return false;
            }
            throw $e;
        }
    }

    public static function insertDailyBalance(PDO $localPdo, array $balance, ?int &$insertedId = null): bool
    {
        try {
            $stmt = $localPdo->prepare(
                'INSERT INTO `bank_daily_balances`
                    (`account_id`, `statement_date`, `currency`, `opening_balance`, `debit_turnover`, `credit_turnover`, `closing_balance`, `dedupe_hash`)
                 VALUES
                    (:account_id, :statement_date, :currency, :opening_balance, :debit_turnover, :credit_turnover, :closing_balance, :dedupe_hash)'
            );

            $stmt->execute([
                ':account_id' => $balance['account_id'],
                ':statement_date' => $balance['statement_date'],
                ':currency' => $balance['currency'] ?? 'RUR',
                ':opening_balance' => $balance['opening_balance'] ?? null,
                ':debit_turnover' => $balance['debit_turnover'] ?? null,
                ':credit_turnover' => $balance['credit_turnover'] ?? null,
                ':closing_balance' => $balance['closing_balance'] ?? null,
                ':dedupe_hash' => $balance['dedupe_hash'] ?? '',
            ]);

            if ($stmt->rowCount() > 0) {
                $insertedId = (int)$localPdo->lastInsertId();
                return true;
            }
            return false;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $insertedId = null;
                return false;
            }
            throw $e;
        }
    }

    public static function linkTransactionToImport(PDO $localPdo, int $importId, int $transactionId): void
    {
        try {
            $stmt = $localPdo->prepare(
                'INSERT IGNORE INTO `bank_import_transactions` (`import_id`, `transaction_id`) VALUES (:import_id, :transaction_id)'
            );
            $stmt->execute([':import_id' => $importId, ':transaction_id' => $transactionId]);
        } catch (PDOException $e) {
        }
    }

    public static function linkBalanceToImport(PDO $localPdo, int $importId, int $balanceId): void
    {
        try {
            $stmt = $localPdo->prepare(
                'INSERT IGNORE INTO `bank_import_balances` (`import_id`, `balance_id`) VALUES (:import_id, :balance_id)'
            );
            $stmt->execute([':import_id' => $importId, ':balance_id' => $balanceId]);
        } catch (PDOException $e) {
        }
    }

    private static function findTransactionIdByDedupeHash(PDO $localPdo, string $dedupeHash): ?int
    {
        $stmt = $localPdo->prepare('SELECT `id` FROM `bank_transactions` WHERE `dedupe_hash` = :hash LIMIT 1');
        $stmt->execute([':hash' => $dedupeHash]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function findBalanceIdByDedupeHash(PDO $localPdo, string $dedupeHash): ?int
    {
        $stmt = $localPdo->prepare('SELECT `id` FROM `bank_daily_balances` WHERE `dedupe_hash` = :hash LIMIT 1');
        $stmt->execute([':hash' => $dedupeHash]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public static function getAccounts(PDO $localPdo): array
    {
        $stmt = $localPdo->query(
            'SELECT ba.*,
                    (SELECT COUNT(*) FROM `bank_transactions` bt WHERE bt.`account_id` = ba.`id`) AS `transaction_count`,
                    (SELECT MAX(`operation_date`) FROM `bank_transactions` bt WHERE bt.`account_id` = ba.`id`) AS `last_transaction_date`,
                    (SELECT MAX(`statement_date`) FROM `bank_daily_balances` bdb WHERE bdb.`account_id` = ba.`id`) AS `last_balance_date`,
                    (SELECT bdb.`closing_balance` FROM `bank_daily_balances` bdb WHERE bdb.`account_id` = ba.`id` ORDER BY bdb.`statement_date` DESC, bdb.`id` DESC LIMIT 1) AS `latest_closing_balance`,
                    (SELECT SUM(`debit_amount`) FROM `bank_transactions` bt WHERE bt.`account_id` = ba.`id`) AS `total_debit`,
                    (SELECT SUM(`credit_amount`) FROM `bank_transactions` bt WHERE bt.`account_id` = ba.`id`) AS `total_credit`,
                    COALESCE(
                        (SELECT bdb.`closing_balance` FROM `bank_daily_balances` bdb WHERE bdb.`account_id` = ba.`id` ORDER BY bdb.`statement_date` DESC, bdb.`id` DESC LIMIT 1),
                        ba.`closing_balance`,
                        (SELECT ROUND(SUM(bt.`credit_amount`) - SUM(bt.`debit_amount`), 2) FROM `bank_transactions` bt WHERE bt.`account_id` = ba.`id`),
                        0
                    ) AS `effective_balance`
             FROM `bank_accounts` ba
             ORDER BY ba.`created_at` DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getDailyBalances(PDO $localPdo, ?int $accountId = null, int $page = 1, int $perPage = 100, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if ($accountId !== null) {
            $conditions[] = 'bdb.`account_id` = :account_id';
            $params[':account_id'] = $accountId;
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $conditions[] = 'bdb.`statement_date` >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $conditions[] = 'bdb.`statement_date` <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM `bank_daily_balances` bdb
                     JOIN `bank_accounts` ba ON bdb.`account_id` = ba.`id`
                     {$where}";
        $countStmt = $localPdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT bdb.*, ba.`account_number`
                FROM `bank_daily_balances` bdb
                JOIN `bank_accounts` ba ON bdb.`account_id` = ba.`id`
                {$where}
                ORDER BY bdb.`statement_date` DESC, bdb.`id` DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $localPdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public static function getImports(PDO $localPdo, int $limit = 50): array
    {
        $stmt = $localPdo->prepare(
            'SELECT * FROM `bank_statement_imports`
             ORDER BY `created_at` DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getTransactions(PDO $localPdo, ?int $accountId = null, int $page = 1, int $perPage = 100, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if ($accountId !== null) {
            $conditions[] = 'bt.`account_id` = :account_id';
            $params[':account_id'] = $accountId;
        }
        if ($dateFrom !== null) {
            $conditions[] = 'bt.`operation_date` >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $conditions[] = 'bt.`operation_date` <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $where = '';
        if (!empty($conditions)) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        $countSql = "SELECT COUNT(*)
                     FROM `bank_transactions` bt
                     JOIN `bank_accounts` ba ON bt.`account_id` = ba.`id`
                     {$where}";
        $countStmt = $localPdo->prepare($countSql);
        foreach ($params as $key => $val) {
            $countStmt->bindValue($key, $val);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT bt.*, ba.`account_number`
                FROM `bank_transactions` bt
                JOIN `bank_accounts` ba ON bt.`account_id` = ba.`id`
                {$where}
                ORDER BY bt.`operation_date` DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $localPdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }

        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public static function importParsedData(PDO $localPdo, array $parsed, string $source = 'manual', ?string $sourceUid = null, ?string $filename = null, ?string $fileHash = null): array
    {
        if ($fileHash === null) {
            $fileHash = hash('sha256', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $fileHash)) {
            return ['status' => 'error', 'message' => 'Invalid file hash', 'import_id' => null];
        }

        $accountNumber = $parsed['account_number'] ?? '';
        if ($accountNumber === '' && $filename !== null && preg_match('/(?<!\d)(\d{20})(?!\d)/', $filename, $m)) {
            $accountNumber = $m[1];
            $parsed['account_number'] = $accountNumber;
        }
        if (!preg_match('/^\d{20}$/', $accountNumber)) {
            return ['status' => 'error', 'message' => 'No account number in file', 'import_id' => null];
        }

        $transactions = is_array($parsed['transactions'] ?? null) ? $parsed['transactions'] : [];
        $dailyBalances = is_array($parsed['daily_balances'] ?? null) ? $parsed['daily_balances'] : [];
        if ($transactions === [] && $dailyBalances === []) {
            return ['status' => 'error', 'message' => 'No financial rows found in file', 'import_id' => null];
        }

        $localPdo->beginTransaction();

        try {
            try {
                $importId = self::createImportRecord($localPdo, [
                    'source' => $source,
                    'source_uid' => $sourceUid,
                    'filename' => $filename,
                    'file_hash' => $fileHash,
                    'account_number' => $accountNumber,
                    'period_from' => $parsed['period_from'] ?? null,
                    'period_to' => $parsed['period_to'] ?? null,
                    'status' => 'pending',
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $localPdo->rollBack();
                    return ['status' => 'skipped', 'message' => 'File already imported', 'import_id' => null];
                }
                throw $e;
            }

            $accountId = self::upsertAccount($localPdo, [
                'account_number' => $accountNumber,
                'bank_name' => null,
                'currency' => 'RUR',
            ]);
            if ($accountId <= 0) {
                throw new \RuntimeException('Cannot resolve bank account');
            }
            // Serialize overlapping imports for one account, including their balance check.
            $lock = $localPdo->prepare('SELECT `id` FROM `bank_accounts` WHERE `id` = ? FOR UPDATE');
            $lock->execute([$accountId]);

            $importedTx = 0;
            $importedBal = 0;
            $updatedBal = 0;
            $newTransactionDates = [];
            $balanceDates = [];

            foreach ($transactions as $tx) {
                $tx['account_id'] = $accountId;
                $tx['dedupe_hash'] = self::transactionDedupeHash($accountNumber, $tx);
                $txId = null;
                if (self::insertTransaction($localPdo, $tx, $txId)) {
                    $importedTx++;
                    $newTransactionDates[(string)$tx['operation_date']] = true;
                }
                if ($txId === null) {
                    $txId = self::findTransactionIdByDedupeHash($localPdo, $tx['dedupe_hash']);
                }
                if ($txId !== null) {
                    self::linkTransactionToImport($localPdo, $importId, $txId);
                    $match = \App\Service\FinanceOperationService::findMatchingTransferForBankTransaction($localPdo, $txId);
                    if ($match !== null && $match['match_quality'] === 'exact') {
                        \App\Service\FinanceOperationService::confirmTransfer(
                            $localPdo,
                            $match['transfer']['transfer_group_id'],
                            $txId,
                            ['user_id' => 0, 'role_code' => 'system']
                        );
                    } else {
                        $opId = \App\Service\FinanceOperationService::createOperationFromBankTransaction($localPdo, $txId);
                        if ($opId !== null) {
                            \App\Service\FinanceMatchingRuleService::applyAutoMatchToOperation($localPdo, $opId);
                        }
                    }
                }
            }

            foreach ($dailyBalances as $balance) {
                $balance['account_id'] = $accountId;
                $balance['dedupe_hash'] = self::balanceDedupeHash($accountNumber, $balance);
                $date = (string)$balance['statement_date'];
                $balanceDates[$date] = true;
                $balId = null;
                $existing = $localPdo->prepare('SELECT * FROM `bank_daily_balances` WHERE `dedupe_hash` = ? FOR UPDATE');
                $existing->execute([$balance['dedupe_hash']]);
                $saved = $existing->fetch(PDO::FETCH_ASSOC);
                if ($saved) {
                    $balId = (int)$saved['id'];
                    if (self::reconcileOverlappingBalance($localPdo, $accountId, $date, $saved, $balance, isset($newTransactionDates[$date]))) {
                        $updatedBal++;
                    }
                } else {
                    if (self::insertDailyBalance($localPdo, $balance, $balId)) {
                        $importedBal++;
                    }
                    if ($balId === null) {
                        $balId = self::findBalanceIdByDedupeHash($localPdo, $balance['dedupe_hash']);
                    }
                }
                if ($balId !== null) {
                    self::linkBalanceToImport($localPdo, $importId, $balId);
                }
            }

            foreach (array_keys($newTransactionDates) as $date) {
                if (isset($balanceDates[$date])) {
                    continue;
                }
                $existing = $localPdo->prepare('SELECT 1 FROM `bank_daily_balances` WHERE `account_id` = ? AND `statement_date` = ? LIMIT 1');
                $existing->execute([$accountId, $date]);
                if ($existing->fetchColumn() !== false) {
                    throw new \RuntimeException("Повторная выписка за {$date} содержит новые операции без подтверждённого остатка. Импорт отменён; загрузите полную выписку банка.");
                }
            }

            \App\Service\FinanceOperationService::backfillBankOperations($localPdo);

            $stmt = $localPdo->prepare(
                "UPDATE bank_statement_imports
                    SET imported_transactions = ?, imported_balances = ?, status = 'completed', error_message = NULL
                  WHERE id = ?"
            );
            $stmt->execute([$importedTx, $importedBal, $importId]);

            self::recomputeAccountSummary($localPdo, $accountNumber);

            $localPdo->commit();
        } catch (\Throwable $e) {
            if ($localPdo->inTransaction()) {
                $localPdo->rollBack();
            }
            throw $e;
        }

        return [
            'status' => 'imported',
            'message' => "Imported {$importedTx} transactions, {$importedBal} daily balances; updated {$updatedBal} daily balances",
            'import_id' => $importId,
            'account_id' => $accountId,
            'transactions_imported' => $importedTx,
            'balances_imported' => $importedBal,
            'balances_updated' => $updatedBal,
        ];
    }

    private static function transactionDedupeHash(string $accountNumber, array $tx): string
    {
        $parts = [
            $accountNumber,
            (string) ($tx['operation_date'] ?? ''),
            trim((string) ($tx['document_number'] ?? '')),
            trim((string) ($tx['operation_type'] ?? '')),
            trim((string) ($tx['counterparty_name'] ?? '')),
            trim((string) ($tx['counterparty_inn'] ?? '')),
            trim((string) ($tx['counterparty_account'] ?? '')),
            self::canonicalAmount($tx['debit_amount'] ?? 0),
            self::canonicalAmount($tx['credit_amount'] ?? 0),
            trim((string) ($tx['purpose'] ?? '')),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private static function balanceDedupeHash(string $accountNumber, array $balance): string
    {
        return hash('sha256', implode('|', [
            $accountNumber,
            (string) ($balance['statement_date'] ?? ''),
            strtoupper(trim((string) ($balance['currency'] ?? 'RUR'))),
        ]));
    }

    private static function reconcileOverlappingBalance(PDO $pdo, int $accountId, string $date, array $saved, array $incoming, bool $hasNewTransactions): bool
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(`debit_amount`), 0) AS debit, COALESCE(SUM(`credit_amount`), 0) AS credit FROM `bank_transactions` WHERE `account_id` = ? AND `operation_date` = ?');
        $stmt->execute([$accountId, $date]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        $debit = self::canonicalAmount($totals['debit'] ?? 0);
        $credit = self::canonicalAmount($totals['credit'] ?? 0);
        $opening = $saved['opening_balance'] === null ? null : self::canonicalAmount($saved['opening_balance']);
        $expectedClosing = $opening === null ? null : FinanceBankReconciliationService::sub(FinanceBankReconciliationService::add($opening, $credit), $debit);
        $savedConsistent = $expectedClosing !== null
            && FinanceBankReconciliationService::compare($expectedClosing, self::canonicalAmount($saved['closing_balance'])) === 0
            && FinanceBankReconciliationService::compare($debit, self::canonicalAmount($saved['debit_turnover'])) === 0
            && FinanceBankReconciliationService::compare($credit, self::canonicalAmount($saved['credit_turnover'])) === 0;

        // Repeated operations and the same confirmed closing balance need no update.
        if (!$hasNewTransactions && $savedConsistent) {
            if ($incoming['closing_balance'] !== null
                && FinanceBankReconciliationService::compare(self::canonicalAmount($incoming['closing_balance']), self::canonicalAmount($saved['closing_balance'])) !== 0) {
                throw new \RuntimeException("Повторная выписка за {$date} сообщает другой остаток без новых операций. Импорт отменён; проверьте банковский файл.");
            }
            return false;
        }
        if ($expectedClosing === null || $incoming['closing_balance'] === null
            || FinanceBankReconciliationService::compare($expectedClosing, self::canonicalAmount($incoming['closing_balance'])) !== 0) {
            throw new \RuntimeException("Обороты повторной выписки за {$date} не сходятся с итогом банка. Импорт отменён; проверьте пересекающиеся файлы.");
        }

        $update = $pdo->prepare('UPDATE `bank_daily_balances` SET `debit_turnover` = ?, `credit_turnover` = ?, `closing_balance` = ? WHERE `id` = ?');
        $update->execute([$debit, $credit, $expectedClosing, (int)$saved['id']]);
        return true;
    }

    private static function canonicalAmount(mixed $amount): string
    {
        if ($amount === null || $amount === '' || $amount === false) {
            return '0.00';
        }
        $normalized = str_replace(',', '.', (string) $amount);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return '0.00';
        }
        $parts = explode('.', $normalized);
        $intPart = $parts[0];
        $decPart = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        return $intPart . '.' . $decPart;
    }

    public static function deleteImport(PDO $localPdo, int $importId): array
    {
        $stmt = $localPdo->prepare('SELECT * FROM `bank_statement_imports` WHERE `id` = ?');
        $stmt->execute([$importId]);
        $import = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$import) {
            return ['status' => 'error', 'message' => 'Import not found'];
        }

        $accountNumber = $import['account_number'];
        $localPdo->beginTransaction();

        try {
            // Capture linked IDs before removing links
            $txIds = $localPdo->prepare('SELECT `transaction_id` FROM `bank_import_transactions` WHERE `import_id` = ?');
            $txIds->execute([$importId]);
            $capturedTxIds = $txIds->fetchAll(PDO::FETCH_COLUMN);

            $balIds = $localPdo->prepare('SELECT `balance_id` FROM `bank_import_balances` WHERE `import_id` = ?');
            $balIds->execute([$importId]);
            $capturedBalIds = $balIds->fetchAll(PDO::FETCH_COLUMN);
            $affectedDates = $localPdo->prepare(
                'SELECT DISTINCT bt.`operation_date` AS `day` FROM `bank_import_transactions` bit
                 JOIN `bank_transactions` bt ON bt.`id` = bit.`transaction_id` WHERE bit.`import_id` = ?
                 UNION SELECT DISTINCT bdb.`statement_date` AS `day` FROM `bank_import_balances` bib
                 JOIN `bank_daily_balances` bdb ON bdb.`id` = bib.`balance_id` WHERE bib.`import_id` = ?'
            );
            $affectedDates->execute([$importId, $importId]);
            $dates = $affectedDates->fetchAll(PDO::FETCH_COLUMN);

            // Remove links for this import
            $linkTxStmt = $localPdo->prepare('DELETE FROM `bank_import_transactions` WHERE `import_id` = ?');
            $linkTxStmt->execute([$importId]);

            $linkBalStmt = $localPdo->prepare('DELETE FROM `bank_import_balances` WHERE `import_id` = ?');
            $linkBalStmt->execute([$importId]);

            // Delete only captured IDs that now have no remaining links
            foreach ($capturedTxIds as $txId) {
                $check = $localPdo->prepare('SELECT COUNT(*) FROM `bank_import_transactions` WHERE `transaction_id` = ?');
                $check->execute([$txId]);
                if ((int)$check->fetchColumn() === 0) {
                    $localPdo->prepare('DELETE FROM `bank_transactions` WHERE `id` = ?')->execute([$txId]);
                }
            }

            foreach ($capturedBalIds as $balId) {
                $check = $localPdo->prepare('SELECT COUNT(*) FROM `bank_import_balances` WHERE `balance_id` = ?');
                $check->execute([$balId]);
                if ((int)$check->fetchColumn() === 0) {
                    $localPdo->prepare('DELETE FROM `bank_daily_balances` WHERE `id` = ?')->execute([$balId]);
                }
            }

            $accountStmt = $localPdo->prepare('SELECT `id` FROM `bank_accounts` WHERE `account_number` = ? LIMIT 1');
            $accountStmt->execute([$accountNumber]);
            $accountId = (int)$accountStmt->fetchColumn();
            foreach ($dates as $date) {
                $balanceStmt = $localPdo->prepare('SELECT * FROM `bank_daily_balances` WHERE `account_id` = ? AND `statement_date` = ?');
                $balanceStmt->execute([$accountId, $date]);
                foreach ($balanceStmt->fetchAll(PDO::FETCH_ASSOC) as $balance) {
                    $totalsStmt = $localPdo->prepare('SELECT COALESCE(SUM(`debit_amount`), 0) AS debit, COALESCE(SUM(`credit_amount`), 0) AS credit FROM `bank_transactions` WHERE `account_id` = ? AND `operation_date` = ?');
                    $totalsStmt->execute([$accountId, $date]);
                    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
                    if (FinanceBankReconciliationService::compare(self::canonicalAmount($balance['debit_turnover']), self::canonicalAmount($totals['debit'])) !== 0
                        || FinanceBankReconciliationService::compare(self::canonicalAmount($balance['credit_turnover']), self::canonicalAmount($totals['credit'])) !== 0) {
                        throw new \RuntimeException('Удаление создаст расхождение оборотов за ' . $date . '. Сначала загрузите корректную полную выписку.');
                    }
                }
            }

            $delStmt = $localPdo->prepare('DELETE FROM `bank_statement_imports` WHERE `id` = ?');
            $delStmt->execute([$importId]);

            self::recomputeAccountSummary($localPdo, $accountNumber);

            $localPdo->commit();

            return ['status' => 'ok', 'message' => 'Import deleted successfully'];
        } catch (\Throwable $e) {
            if ($localPdo->inTransaction()) {
                $localPdo->rollBack();
            }
            return ['status' => 'error', 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Ошибка удаления импорта.'];
        }
    }

    public static function recomputeAccountSummary(PDO $localPdo, string $accountNumber): void
    {
        $stmt = $localPdo->prepare('SELECT `id` FROM `bank_accounts` WHERE `account_number` = ? LIMIT 1');
        $stmt->execute([$accountNumber]);
        $accountId = $stmt->fetchColumn();
        if ($accountId === false) {
            return;
        }
        $accountId = (int)$accountId;

        $hasData = $localPdo->prepare(
            'SELECT COUNT(*) FROM (
                SELECT 1 FROM `bank_transactions` WHERE `account_id` = ?
                UNION ALL
                SELECT 1 FROM `bank_daily_balances` WHERE `account_id` = ?
            ) t'
        );
        $hasData->execute([$accountId, $accountId]);
        $count = (int)$hasData->fetchColumn();

        if ($count === 0) {
            $hasImports = $localPdo->prepare('SELECT COUNT(*) FROM `bank_statement_imports` WHERE `account_number` = ?');
            $hasImports->execute([$accountNumber]);
            $importCount = (int)$hasImports->fetchColumn();
            if ($importCount === 0) {
                $localPdo->prepare('DELETE FROM `bank_accounts` WHERE `id` = ?')->execute([$accountId]);
            } else {
                $localPdo->prepare(
                    'UPDATE `bank_accounts` SET `opening_balance` = NULL, `closing_balance` = NULL WHERE `id` = ?'
                )->execute([$accountId]);
            }
            return;
        }

        // Recompute from the latest daily balance
        $latestBal = $localPdo->prepare(
            'SELECT `opening_balance`, `closing_balance` FROM `bank_daily_balances`
             WHERE `account_id` = ?
             ORDER BY `statement_date` DESC, `id` DESC LIMIT 1'
        );
        $latestBal->execute([$accountId]);
        $latest = $latestBal->fetch(PDO::FETCH_ASSOC);

        if ($latest) {
            $update = $localPdo->prepare(
                'UPDATE `bank_accounts` SET `opening_balance` = ?, `closing_balance` = ? WHERE `id` = ?'
            );
            $update->execute([$latest['opening_balance'], $latest['closing_balance'], $accountId]);
        } else {
            // No balances remain; compute closing from transactions
            $stmt = $localPdo->prepare(
                'SELECT COALESCE(SUM(`credit_amount`), 0) - COALESCE(SUM(`debit_amount`), 0) AS closing
                 FROM `bank_transactions` WHERE `account_id` = ?'
            );
            $stmt->execute([$accountId]);
            $closing = $stmt->fetchColumn();
            $update = $localPdo->prepare(
                'UPDATE `bank_accounts` SET `opening_balance` = NULL, `closing_balance` = ? WHERE `id` = ?'
            );
            $update->execute([$closing, $accountId]);
        }
    }

    public static function getImportById(PDO $localPdo, int $importId): ?array
    {
        $stmt = $localPdo->prepare('SELECT * FROM `bank_statement_imports` WHERE `id` = ?');
        $stmt->execute([$importId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
