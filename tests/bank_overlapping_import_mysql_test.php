<?php

declare(strict_types=1);

namespace App\Service {
    final class FinanceOperationService
    {
        public static function findMatchingTransferForBankTransaction(\PDO $pdo, int $id): ?array { return null; }
        public static function createOperationFromBankTransaction(\PDO $pdo, int $id): ?int { return null; }
        public static function backfillBankOperations(\PDO $pdo): array { return []; }
    }
}

namespace {
    require_once __DIR__ . '/../app/Service/FinanceBankReconciliationService.php';
    require_once __DIR__ . '/../app/Service/BankFinanceService.php';
    require_once __DIR__ . '/../app/Service/BankStatementXlsxParser.php';

    $schema = getenv('PLANEX_TEST_DB_LEASE');
    if (!$schema || $schema !== getenv('PLANEX_TEST_FIXED_DB')) {
        throw new \RuntimeException('Isolated database lease required');
    }
    $pdo = new \PDO(
        'mysql:host=' . getenv('PLANEX_SCHEMA_DB_HOST') . ';port=' . getenv('PLANEX_SCHEMA_DB_PORT') . ';dbname=' . $schema . ';charset=utf8mb4',
        getenv('PLANEX_SCHEMA_DB_USER'),
        getenv('PLANEX_SCHEMA_DB_PASSWORD'),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
    );
    foreach (['042_create_bank_finance.sql', '044_create_bank_import_links.sql'] as $file) {
        $sql = file_get_contents(__DIR__ . '/../database/migrations-local/' . $file);
        if ($file === '044_create_bank_import_links.sql') $sql = explode('-- Backfill legacy data:', $sql)[0];
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') $pdo->exec($statement);
        }
    }
    $expect = static function (bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    };
    $account = '40702810500810141833';
    $date = '2026-09-30';
    $tx = static function (string $number, string $amount) use ($date): array {
        return ['operation_date' => $date, 'document_number' => $number, 'operation_type' => 'DEBIT',
            'counterparty_name' => 'Bank', 'counterparty_inn' => '', 'counterparty_account' => '',
            'debit_amount' => $amount, 'credit_amount' => '0.00', 'purpose' => 'Test ' . $number];
    };
    $balance = static function (string $opening, string $debit, string $closing) use ($date): array {
        return ['statement_date' => $date, 'currency' => 'RUR', 'opening_balance' => $opening,
            'debit_turnover' => $debit, 'credit_turnover' => '0.00', 'closing_balance' => $closing];
    };
    $parsed = static function (array $transactions, array $balances) use ($account, $date): array {
        return ['account_number' => $account, 'period_from' => $date, 'period_to' => $date,
            'transactions' => $transactions, 'daily_balances' => $balances];
    };
    $hash = static fn(string $text): string => hash('sha256', $text);
    $first = $parsed([
        $tx('1', '12000.00'), $tx('2', '42000.00'), $tx('3', '90000.00'),
        $tx('4', '180000.00'), $tx('5', '270000.00'), $tx('6', '290000.00'),
    ], [$balance('994432.23', '884000.00', '110432.23')]);
    $r = \App\Service\BankFinanceService::importParsedData($pdo, $first, 'manual', null, 'first.xlsx', $hash('first'));
    $expect($r['transactions_imported'] === 6 && $r['balances_imported'] === 1, 'Initial import');

    $lateTransactions = [$tx('7', '8.00'), $tx('8', '200.00'), $tx('9', '202.00'), $tx('10', '2400.00')];
    $late = $parsed($lateTransactions, [$balance('110432.23', '2810.00', '107622.23')]);
    $r = \App\Service\BankFinanceService::importParsedData($pdo, $late, 'imap', null, 'late.xlsx', $hash('late'));
    $expect($r['transactions_imported'] === 4 && $r['balances_imported'] === 0 && $r['balances_updated'] === 1, 'Late incremental import');
    $lateImportId = (int)$r['import_id'];
    $saved = $pdo->query('SELECT debit_turnover, closing_balance FROM bank_daily_balances')->fetch();
    $expect($saved['debit_turnover'] === '886810.00' && $saved['closing_balance'] === '107622.23', 'Full day balance updated');
    $expect(count(\App\Service\FinanceBankReconciliationService::checkTransactionAggregates($pdo, 1)['mismatches']) === 0, 'Independent reconciliation');

    // Replaying a saved bank file repairs a legacy first-write-wins snapshot.
    $pdo->exec("UPDATE bank_daily_balances SET debit_turnover = 884000.00, closing_balance = 110432.23");
    $pdo->exec("UPDATE bank_accounts SET closing_balance = 110432.23");
    $r = \App\Service\BankFinanceService::importParsedData($pdo, $first, 'manual', null, 'first.xlsx', $hash('first'));
    $expect($r['status'] === 'skipped', 'Older partial file can be retained without changing balance');
    $r = \App\Service\BankFinanceService::importParsedData($pdo, $late, 'imap', null, 'late.xlsx', $hash('late'));
    $expect($r['status'] === 'reconciled' && $r['balances_updated'] === 1, 'Replay repairs legacy mismatch from bank source');
    $saved = $pdo->query('SELECT debit_turnover, closing_balance FROM bank_daily_balances')->fetch();
    $expect($saved['debit_turnover'] === '886810.00' && $saved['closing_balance'] === '107622.23', 'Replay updates full-day balance');
    $r = \App\Service\BankFinanceService::importParsedData($pdo, $late, 'imap', null, 'late.xlsx', $hash('late'));
    $expect($r['status'] === 'skipped', 'Already repaired file skipped');

    $failed = false;
    try {
        \App\Service\BankFinanceService::importParsedData($pdo,
            $parsed([$tx('11', '5.00')], [$balance('107622.23', '5.00', '110432.23')]),
            'imap', null, 'conflict.xlsx', $hash('conflict'));
    } catch (\RuntimeException $e) {
        $failed = str_contains($e->getMessage(), 'Импорт отменён');
    }
    $expect($failed, 'Conflicting snapshot rejected');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn() === 10, 'Conflict transaction rolled back');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM bank_statement_imports')->fetchColumn() === 2, 'Conflict import rolled back');

    $failed = false;
    try {
        \App\Service\BankFinanceService::importParsedData($pdo,
            $parsed([$tx('12', '5.00')], []), 'imap', null, 'no-balance.xlsx', $hash('no-balance'));
    } catch (\RuntimeException $e) {
        $failed = str_contains($e->getMessage(), 'без подтверждённого остатка');
    }
    $expect($failed, 'Overlapping transaction without balance rejected');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn() === 10, 'No-balance transaction rolled back');
    $deleted = \App\Service\BankFinanceService::deleteImport($pdo, $lateImportId);
    $expect($deleted['status'] === 'error', 'Cannot delete a linked import and leave a conflicting day');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM bank_transactions')->fetchColumn() === 10, 'Unsafe deletion rolled back');

    $parser = new \App\Service\BankStatementXlsxParser();
    $parseSheet = new \ReflectionMethod($parser, 'parseFullStatement');
    $multiDay = $parseSheet->invoke($parser, [
        2 => ['A2' => $account],
        3 => ['A3' => '13.10.2026 — 14.10.2026'],
        4 => ['B4' => '832 804,03', 'D4' => '226 464,03'],
        7 => ['A7' => 'Дата', 'B7' => 'Номер', 'C7' => 'Дебет', 'D7' => 'Кредит', 'E7' => 'Назначение',
            'F7' => 'Вид операции', 'G7' => 'Контрагент', 'H7' => 'ИНН', 'I7' => 'БИК'],
        8 => ['A8' => '13.10.2026', 'B8' => '13', 'C8' => '316 340,00'],
        9 => ['A9' => '14.10.2026', 'B9' => '14a', 'C9' => '120 000,00'],
        10 => ['A10' => '14.10.2026', 'B10' => '14b', 'C10' => '170 000,00'],
    ], $account);
    $r = \App\Service\BankFinanceService::importParsedData($pdo, $multiDay, 'manual', null, 'multi-day.xlsx', $hash('multi-day'));
    $expect($r['transactions_imported'] === 3 && $r['balances_imported'] === 2, 'Multi-day import stores two daily balances');
    $saved = $pdo->query("SELECT statement_date, opening_balance, debit_turnover, closing_balance FROM bank_daily_balances WHERE statement_date >= '2026-10-13' ORDER BY statement_date")->fetchAll();
    $expect(count($saved) === 2 && $saved[0]['debit_turnover'] === '316340.00'
        && $saved[1]['opening_balance'] === '516464.03' && $saved[1]['debit_turnover'] === '290000.00',
        'Multi-day import keeps daily turnover and continuity');
    $expect(count(\App\Service\FinanceBankReconciliationService::checkTransactionAggregates($pdo, 1)['mismatches']) === 0,
        'Multi-day import passes independent aggregate reconciliation');
    echo "BANK_OVERLAPPING_IMPORT=PASS\n";
}
