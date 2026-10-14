<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/BankStatementXlsxParser.php';

$parser = new \App\Service\BankStatementXlsxParser();
$method = new ReflectionMethod($parser, 'parseFullStatement');
$account = '40702810500810141833';
$cells = [
    2 => ['A2' => $account],
    3 => ['A3' => '13.10.2026 — 14.10.2026'],
    4 => ['B4' => '832 804,03', 'D4' => '226 464,03'],
    7 => ['A7' => 'Дата', 'B7' => 'Номер', 'C7' => 'Дебет', 'D7' => 'Кредит', 'E7' => 'Назначение',
        'F7' => 'Вид операции', 'G7' => 'Контрагент', 'H7' => 'ИНН', 'I7' => 'БИК'],
    8 => ['A8' => '13.10.2026', 'B8' => '1', 'C8' => '316 340,00', 'E8' => 'Операция 13 октября'],
    9 => ['A9' => '14.10.2026', 'B9' => '2', 'C9' => '120 000,00', 'E9' => 'Операция 14 октября'],
    10 => ['A10' => '14.10.2026', 'B10' => '3', 'C10' => '170 000,00', 'E10' => 'Операция 14 октября'],
];
$parsed = $method->invoke($parser, $cells, $account);
$balances = $parsed['daily_balances'] ?? [];
if (count($parsed['transactions'] ?? []) !== 3 || count($balances) !== 2) {
    throw new RuntimeException('Multi-day statement did not retain all operations and daily balances.');
}
if ($balances[0]['statement_date'] !== '2026-10-13'
    || $balances[0]['opening_balance'] !== '832804.03'
    || $balances[0]['debit_turnover'] !== '316340.00'
    || $balances[0]['closing_balance'] !== '516464.03'
    || $balances[1]['statement_date'] !== '2026-10-14'
    || $balances[1]['opening_balance'] !== '516464.03'
    || $balances[1]['debit_turnover'] !== '290000.00'
    || $balances[1]['closing_balance'] !== '226464.03') {
    throw new RuntimeException('Multi-day turnover was not assigned to its operation date.');
}
$singleDay = $cells;
$singleDay[3] = ['A3' => '14.10.2026 — 14.10.2026'];
$singleDay[4] = ['B4' => '516 464,03', 'D4' => '226 464,03'];
unset($singleDay[8]);
$single = $method->invoke($parser, $singleDay, $account);
if (count($single['daily_balances'] ?? []) !== 1
    || $single['daily_balances'][0]['opening_balance'] !== '516464.03'
    || $single['daily_balances'][0]['debit_turnover'] !== '290000.00') {
    throw new RuntimeException('Single-day statement regression.');
}
echo "BANK_MULTIDAY_XLSX_BALANCE=PASS\n";
