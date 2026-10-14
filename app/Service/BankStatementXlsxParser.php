<?php

namespace App\Service;

use ZipArchive;
use SimpleXMLElement;

final class BankStatementXlsxParser
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_ZIP_ENTRIES = 2000;
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    private array $sharedStrings = [];
    private array $sheetMap = [];

    public function parse(string $filePath): array
    {
        $this->sharedStrings = [];
        $this->sheetMap = [];

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('XLSX file is not readable.');
        }
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('XLSX file size is invalid or exceeds 20 MB.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Cannot open XLSX file.');
        }

        $this->validateArchive($zip);

        $this->loadSharedStrings($zip);
        $this->loadSheetMap($zip);

        $result = [
            'type' => null,
            'account_number' => null,
            'period_from' => null,
            'period_to' => null,
            'transactions' => [],
            'daily_balances' => [],
        ];

        foreach ($this->sheetMap as $sheetName => $xmlPath) {
            $content = $zip->getFromName($xmlPath);
            if ($content === false) {
                continue;
            }

            $xml = $this->parseXml($content);
            if ($xml === null) {
                continue;
            }

            $ns = $xml->getNamespaces(true);
            $mainNs = $ns[''] ?? '';

            $rows = [];
            if ($mainNs !== '') {
                $xml->registerXPathNamespace('m', $mainNs);
                $rows = $xml->xpath('//m:sheetData/m:row') ?? [];
            }
            if (count($rows) === 0) {
                if (isset($xml->sheetData)) {
                    foreach ($xml->sheetData->row as $r) {
                        $rows[] = $r;
                    }
                }
            }

            if (count($rows) === 0) {
                continue;
            }

            $cells = [];
            foreach ($rows as $row) {
                $rowIndex = (int)$row['r'];
                $rowCells = [];
                foreach ($row->c as $cell) {
                    $colRef = (string)$cell['r'];
                    $value = $this->getCellValue($cell);
                    $rowCells[$colRef] = $value;
                }
                $cells[$rowIndex] = $rowCells;
            }

            if (count($cells) === 0) {
                continue;
            }

            if ($this->isDailyReportSheet($cells, $sheetName)) {
                $dailyData = $this->parseDailyReport($cells);
                if ($dailyData !== null) {
                    $result['type'] = 'daily';
                    $result['daily_balances'] = $dailyData['balances'];
                    if ($result['account_number'] === null) {
                        $result['account_number'] = $dailyData['account_number'];
                    }
                    if ($result['period_from'] === null && $dailyData['period_from'] !== null) {
                        $result['period_from'] = $dailyData['period_from'];
                    }
                    if ($result['period_to'] === null && $dailyData['period_to'] !== null) {
                        $result['period_to'] = $dailyData['period_to'];
                    }
                }
            } else {
                $statementData = $this->parseFullStatement($cells, $sheetName);
                if ($statementData !== null) {
                    $result['type'] = 'full';
                    if ($result['account_number'] === null) {
                        $result['account_number'] = $statementData['account_number'];
                    }
                    if ($result['period_from'] === null && $statementData['period_from'] !== null) {
                        $result['period_from'] = $statementData['period_from'];
                    }
                    if ($result['period_to'] === null && $statementData['period_to'] !== null) {
                        $result['period_to'] = $statementData['period_to'];
                    }
                    $result['transactions'] = array_merge($result['transactions'], $statementData['transactions']);
                    if (!empty($statementData['daily_balances'])) {
                        $result['daily_balances'] = array_merge($result['daily_balances'], $statementData['daily_balances']);
                    }
                }
            }
        }

        $zip->close();
        return $result;
    }

    private function loadSharedStrings(ZipArchive $zip): void
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return;
        }

        $xml = $this->parseXml($content);
        if ($xml === null) {
            return;
        }

        foreach ($xml->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string)$run->t;
                }
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function loadSheetMap(ZipArchive $zip): void
    {
        $content = $zip->getFromName('xl/workbook.xml');
        if ($content === false) {
            return;
        }

        $xml = $this->parseXml($content);
        if ($xml === null) {
            return;
        }

        $ns = $xml->getNamespaces(true);
        $mainNs = $ns[''] ?? '';

        // Register namespace for xpath
        $xml->registerXPathNamespace('m', $mainNs);
        $sheets = $xml->xpath('//m:sheets/m:sheet');
        if ($sheets === false || count($sheets) === 0) {
            // Fallback: try direct children
            $sheets = [];
            if (isset($xml->sheets)) {
                foreach ($xml->sheets->sheet as $s) {
                    $sheets[] = $s;
                }
            }
        }

        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relMap = [];
        if ($relsContent !== false) {
            $relsXml = $this->parseXml($relsContent);
            if ($relsXml !== null) {
                foreach ($relsXml->Relationship as $rel) {
                    $id = (string)$rel['Id'];
                    $target = (string)$rel['Target'];
                    $relMap[$id] = $target;
                }
            }
        }

        $rNs = $ns['r'] ?? '';

        foreach ($sheets as $sheet) {
            $name = (string)$sheet['name'];
            $rId = '';
            if ($rNs !== '') {
                $attrs = $sheet->attributes($rNs);
                if ($attrs && isset($attrs->id)) {
                    $rId = (string)$attrs->id;
                }
            }
            if ($rId === '') {
                $rId = (string)$sheet['id'];
            }
            $target = $relMap[$rId] ?? '';
            if ($target !== '') {
                $xmlPath = 'xl/' . ltrim($target, '/');
                $this->sheetMap[$name] = $xmlPath;
            }
        }
    }

    private function getCellValue(SimpleXMLElement $cell): string
    {
        $type = (string)$cell['t'];
        $value = '';

        if ($type === 's' && isset($cell->v)) {
            $index = (int)$cell->v;
            $value = $this->sharedStrings[$index] ?? '';
        } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
            $value = (string)$cell->is->t;
        } elseif (isset($cell->v)) {
            $value = (string)$cell->v;
        }

        return trim($value);
    }

    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw new \RuntimeException('XLSX archive contains an invalid number of entries.');
        }

        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw new \RuntimeException('XLSX archive is too large after decompression.');
            }
        }

        foreach (['[Content_Types].xml', 'xl/workbook.xml'] as $requiredEntry) {
            if ($zip->locateName($requiredEntry, ZipArchive::FL_NOCASE) === false) {
                $zip->close();
                throw new \RuntimeException('File is not a valid XLSX workbook.');
            }
        }
    }

    private function parseXml(string $content): ?SimpleXMLElement
    {
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            throw new \RuntimeException('Unsafe XML content in XLSX file.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            return $xml !== false ? $xml : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function getCellByIndex(array $cells, int $colIndex): string
    {
        $colLetter = $this->indexToColumn($colIndex);
        return $cells[$colLetter] ?? '';
    }

    private function indexToColumn(int $index): string
    {
        $result = '';
        while ($index >= 0) {
            $result = chr(65 + ($index % 26)) . $result;
            $index = (int)($index / 26) - 1;
        }
        return $result;
    }

    private function isDailyReportSheet(array $cells, string $sheetName): bool
    {
        if (mb_stripos($sheetName, 'Отчет по оборотам и остаткам') !== false) {
            return true;
        }
        if (mb_stripos($sheetName, 'Выписка по операциям и остаткам') !== false) {
            return true;
        }

        $firstRow = $cells[1] ?? [];
        $row1Values = implode(' ', $firstRow);
        if (mb_stripos($row1Values, 'Выписка по операциям и остаткам') !== false) {
            return true;
        }
        if (mb_stripos($row1Values, 'Отчет по оборотам и остаткам') !== false) {
            return true;
        }

        if (count($cells) >= 6) {
            $headerRow = $cells[6] ?? [];
            $headerValues = implode(' ', $headerRow);
            if (mb_stripos($headerValues, 'Дата выписки') !== false) {
                return true;
            }
        }

        return false;
    }

    private function parseDailyReport(array $cells): ?array
    {
        $firstRow = $cells[1] ?? [];

        if (!isset($cells[6])) {
            return null;
        }

        $headerRow = $cells[6] ?? [];
        $headers = [];
        foreach ($headerRow as $colRef => $val) {
            $colNum = $this->columnToIndex($colRef);
            if ($colNum !== null) {
                $headers[$colNum] = $val;
            }
        }

        $accountNumber = null;
        $periodFrom = null;
        $periodTo = null;
        $balances = [];

        $row1Text = implode(' ', $firstRow);

        for ($rowIdx = 1; $rowIdx <= 5; $rowIdx++) {
            $row = $cells[$rowIdx] ?? [];
            $rowText = implode(' ', $row);

            if ($accountNumber === null && preg_match('/\b\d{20}\b/', $rowText, $m)) {
                $accountNumber = $m[0];
            }
            if ($accountNumber === null && preg_match('/\b\d{11}\b/', $rowText, $m)) {
                $accountNumber = $m[0];
            }

            if ($rowIdx === 2) {
                if (preg_match('/(\d{2}\.\d{2}\.\d{4}).*?(\d{2}\.\d{2}\.\d{4})/s', $rowText, $dm)) {
                    $periodFrom = $this->parseDate($dm[1]);
                    $periodTo = $this->parseDate($dm[2]);
                } elseif (preg_match('/\d{2}\.\d{2}\.\d{4}/', $rowText, $dm)) {
                    $periodFrom = $this->parseDate($dm[0]);
                }
            }
        }

        $dataRows = [];
        foreach ($cells as $rowIdx => $row) {
            if ($rowIdx <= 6) {
                continue;
            }
            $dataRows[] = $row;
        }

        foreach ($dataRows as $row) {
            $rowValues = [];
            foreach ($row as $colRef => $val) {
                $colNum = $this->columnToIndex($colRef);
                if ($colNum !== null) {
                    $rowValues[$colNum] = $val;
                }
            }

            $statementDate = null;
            $currency = 'RUR';
            $openingBalance = null;
            $debitTurnover = null;
            $creditTurnover = null;
            $closingBalance = null;

            foreach ($headers as $colNum => $header) {
                $headerLower = mb_strtolower(trim($header));

                if (mb_strpos($headerLower, 'дата выписки') !== false || mb_strpos($headerLower, 'дата') === 0) {
                    $raw = $rowValues[$colNum] ?? '';
                    $statementDate = $this->parseDate($raw);
                } elseif (mb_strpos($headerLower, 'исходящий остаток') !== false) {
                    $raw = $rowValues[$colNum] ?? '';
                    $closingBalance = $this->parseAmount($raw);
                } elseif (mb_strpos($headerLower, 'дебетовые обороты') !== false) {
                    $raw = $rowValues[$colNum] ?? '';
                    $debitTurnover = $this->parseAmount($raw);
                } elseif (mb_strpos($headerLower, 'кредитовые обороты') !== false) {
                    $raw = $rowValues[$colNum] ?? '';
                    $creditTurnover = $this->parseAmount($raw);
                } elseif (mb_strpos($headerLower, 'входящий остаток') !== false) {
                    $raw = $rowValues[$colNum] ?? '';
                    $openingBalance = $this->parseAmount($raw);
                } elseif (mb_strpos($headerLower, 'валюта') !== false) {
                    $currency = $rowValues[$colNum] ?? 'RUR';
                }
            }

            if ($statementDate !== null) {
                $dedupeKey = ($accountNumber ?? '') . '|' . $statementDate . '|' . $currency;
                $balances[] = [
                    'account_number' => $accountNumber,
                    'statement_date' => $statementDate,
                    'currency' => $currency,
                    'opening_balance' => $openingBalance,
                    'debit_turnover' => $debitTurnover,
                    'credit_turnover' => $creditTurnover,
                    'closing_balance' => $closingBalance,
                    'dedupe_hash' => hash('sha256', $dedupeKey),
                ];
            }
        }

        if ($accountNumber === null && count($cells) > 0) {
            foreach ($cells as $row) {
                $rowText = implode(' ', $row);
                if (preg_match('/\b\d{20}\b/', $rowText, $m)) {
                    $accountNumber = $m[0];
                    break;
                }
            }
        }

        return [
            'account_number' => $accountNumber,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'balances' => $balances,
        ];
    }

    private function parseFullStatement(array $cells, string $sheetName): ?array
    {
        $accountNumber = null;
        $periodFrom = null;
        $periodTo = null;
        $transactions = [];
        $daily_balances = [];

        if (preg_match('/\b\d{20}\b/', $sheetName, $m)) {
            $accountNumber = $m[0];
        }

        $row2 = $cells[2] ?? [];
        $row3 = $cells[3] ?? [];
        $row4 = $cells[4] ?? [];

        $row2Text = implode(' ', $row2);
        $row3Text = implode(' ', $row3);
        $row4Text = implode(' ', $row4);

        if ($accountNumber === null && preg_match('/\b\d{20}\b/', $row2Text, $m)) {
            $accountNumber = $m[0];
        }

        if (preg_match('/(\d{2}\.\d{2}\.\d{4}).*?(\d{2}\.\d{2}\.\d{4})/s', $row3Text, $dm)) {
            $periodFrom = $this->parseDate($dm[1]);
            $periodTo = $this->parseDate($dm[2]);
        } elseif (preg_match('/\d{2}\.\d{2}\.\d{4}/', $row3Text, $dm)) {
            $periodFrom = $this->parseDate($dm[0]);
        }

        $headers = [];
        $headerRow = $cells[7] ?? [];
        foreach ($headerRow as $colRef => $val) {
            $colNum = $this->columnToIndex($colRef);
            if ($colNum !== null && trim((string)$val) !== '') {
                $headers[$colNum] = $val;
            }
        }

        $nonEmptyHeaderCount = count($headers);
        if ($nonEmptyHeaderCount === 0) {
            return $this->parseVtbNumericStatement($cells, $accountNumber, $periodFrom, $periodTo);
        }

        $openingBalance = null;
        $closingBalance = null;
        if (isset($row4['B4'])) {
            $openingBalance = $this->parseAmount($row4['B4']);
        }
        if (isset($row4['D4'])) {
            $closingBalance = $this->parseAmount($row4['D4']);
        }

        $headerColumns = [];
        foreach ($headers as $colNum => $header) {
            $headerLower = mb_strtolower(trim($header));
            if (mb_strpos($headerLower, 'дата') === 0) {
                $headerColumns['date'] = $colNum;
            } elseif (mb_strpos($headerLower, 'номер') === 0) {
                $headerColumns['document_number'] = $colNum;
            } elseif (mb_strpos($headerLower, 'вид операции') === 0 || mb_strpos($headerLower, 'вид') === 0) {
                $headerColumns['operation_type'] = $colNum;
            } elseif (mb_strpos($headerLower, 'контрагент') === 0 && mb_strpos($headerLower, 'инн') === false && mb_strpos($headerLower, 'бик') === false && mb_strpos($headerLower, 'счёт') === false) {
                $headerColumns['counterparty_name'] = $colNum;
            } elseif (mb_strpos($headerLower, 'инн') !== false) {
                $headerColumns['counterparty_inn'] = $colNum;
            } elseif (mb_strpos($headerLower, 'бик') !== false) {
                $headerColumns['counterparty_bank_bik'] = $colNum;
            } elseif ((mb_strpos($headerLower, 'счет') === 0 || mb_strpos($headerLower, 'счёт') === 0) && mb_strpos($headerLower, 'контрагента') !== false) {
                $headerColumns['counterparty_account'] = $colNum;
            } elseif (mb_strpos($headerLower, 'дебет') === 0) {
                $headerColumns['debit'] = $colNum;
            } elseif (mb_strpos($headerLower, 'кредит') === 0) {
                $headerColumns['credit'] = $colNum;
            } elseif (mb_strpos($headerLower, 'сумма') === 0) {
                $headerColumns['amount'] = $colNum;
            } elseif (mb_strpos($headerLower, 'приход') === 0) {
                $headerColumns['credit'] = $colNum;
            } elseif (mb_strpos($headerLower, 'назначение') === 0) {
                $headerColumns['purpose'] = $colNum;
            }
        }

        foreach ($cells as $rowIdx => $row) {
            if ($rowIdx <= 7) {
                continue;
            }

            $rowValues = [];
            foreach ($row as $colRef => $val) {
                $colNum = $this->columnToIndex($colRef);
                if ($colNum !== null) {
                    $rowValues[$colNum] = $val;
                }
            }

            $date = null;
            if (isset($headerColumns['date'])) {
                $raw = $rowValues[$headerColumns['date']] ?? '';
                $date = $this->parseDate($raw);
            }

            if ($date === null) {
                continue;
            }

            $docNumber = $rowValues[$headerColumns['document_number']] ?? '';
            $opType = $rowValues[$headerColumns['operation_type']] ?? '';
            $counterparty = $rowValues[$headerColumns['counterparty_name']] ?? '';
            $counterpartyInn = $rowValues[$headerColumns['counterparty_inn']] ?? '';
            $counterpartyBik = $rowValues[$headerColumns['counterparty_bank_bik']] ?? '';
            $counterpartyAccount = isset($headerColumns['counterparty_account']) ? ($rowValues[$headerColumns['counterparty_account']] ?? '') : '';
            $purpose = $rowValues[$headerColumns['purpose']] ?? '';

            $debitAmount = '0.00';
            $creditAmount = '0.00';

            if (isset($headerColumns['debit'])) {
                $debitRaw = $rowValues[$headerColumns['debit']] ?? '';
                $parsed = $this->parseAmount($debitRaw);
                if ($parsed > 0) {
                    $debitAmount = $parsed;
                }
            }

            if (isset($headerColumns['credit'])) {
                $creditRaw = $rowValues[$headerColumns['credit']] ?? '';
                $parsed = $this->parseAmount($creditRaw);
                if ($parsed > 0) {
                    $creditAmount = $parsed;
                }
            }

            if (isset($headerColumns['amount']) && $debitAmount === '0.00' && $creditAmount === '0.00') {
                $amountRaw = $rowValues[$headerColumns['amount']] ?? '';
                $amount = $this->parseAmount($amountRaw);

                if ($amount !== '0.00') {
                    if ($amount > 0) {
                        $creditAmount = $amount;
                    } elseif ($amount < 0) {
                        $debitAmount = substr($amount, 1);
                    }
                }
            }

            $dedupeKey = ($accountNumber ?? '') . '|' . $date . '|' . $docNumber . '|' . $debitAmount . '|' . $creditAmount . '|' . $counterparty . '|' . $purpose;
            $dedupeHash = hash('sha256', $dedupeKey);

            $transactions[] = [
                'account_number' => $accountNumber,
                'operation_date' => $date,
                'document_number' => $docNumber,
                'operation_type' => $opType,
                'counterparty_name' => $counterparty,
                'counterparty_inn' => $counterpartyInn,
                'counterparty_bank_bik' => $counterpartyBik,
                'counterparty_account' => $counterpartyAccount,
                'debit_amount' => $debitAmount,
                'credit_amount' => $creditAmount,
                'purpose' => $purpose,
                'dedupe_hash' => $dedupeHash,
            ];
        }

        if ($openingBalance !== null || $closingBalance !== null) {
            $totalDebitCents = 0;
            $totalCreditCents = 0;
            foreach ($transactions as $tx) {
                $debStr = $tx['debit_amount'] ?? '0';
                $credStr = $tx['credit_amount'] ?? '0';
                $totalDebitCents += (int)str_replace('.', '', $debStr);
                $totalCreditCents += (int)str_replace('.', '', $credStr);
            }
            $totalDebit = sprintf('%d.%02d', intdiv($totalDebitCents, 100), $totalDebitCents % 100);
            $totalCredit = sprintf('%d.%02d', intdiv($totalCreditCents, 100), $totalCreditCents % 100);
            $openCents = (int)str_replace('.', '', $openingBalance ?? '0.00');
            $closeCents = (int)str_replace('.', '', $closingBalance ?? '0.00');
            $calcCloseCents = $openCents + $totalCreditCents - $totalDebitCents;
            $calculatedClosing = sprintf('%d.%02d', intdiv(abs($calcCloseCents), 100), abs($calcCloseCents) % 100);
            if ($calcCloseCents < 0) { $calculatedClosing = '-' . $calculatedClosing; }

            if ($closingBalance !== null && $closingBalance !== '0.00' && abs($calcCloseCents - $closeCents) > 1) {
                $closingBalance = $calculatedClosing;
            } elseif ($closingBalance === null || $closingBalance === '0.00') {
                $closingBalance = $calculatedClosing;
            }

            // The header balances cover the whole file period. A multi-day XLSX
            // must not store that period's turnover as the last day's turnover.
            $firstDate = $periodFrom ?? ($transactions !== [] ? min(array_column($transactions, 'operation_date')) : null);
            $lastDate = $periodTo ?? ($transactions !== [] ? max(array_column($transactions, 'operation_date')) : $firstDate);
            if ($firstDate === null || $lastDate === null || $firstDate > $lastDate) {
                throw new \RuntimeException('Cannot determine bank statement balance period.');
            }
            $start = new \DateTimeImmutable($firstDate);
            $end = new \DateTimeImmutable($lastDate);
            if ($start->diff($end)->days > 3660) {
                throw new \RuntimeException('Bank statement balance period is too long.');
            }
            if ($openingBalance === null && $firstDate !== $lastDate) {
                throw new \RuntimeException('Multi-day bank statement has no opening balance.');
            }
            $dailyTotals = [];
            foreach ($transactions as $tx) {
                $date = $tx['operation_date'];
                if ($date < $firstDate || $date > $lastDate) {
                    throw new \RuntimeException('Bank transaction date is outside the statement period.');
                }
                $dailyTotals[$date]['debit'] = ($dailyTotals[$date]['debit'] ?? 0) + (int)str_replace('.', '', $tx['debit_amount']);
                $dailyTotals[$date]['credit'] = ($dailyTotals[$date]['credit'] ?? 0) + (int)str_replace('.', '', $tx['credit_amount']);
            }
            $runningCents = $openCents;
            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                $balanceDate = $day->format('Y-m-d');
                $dayDebitCents = $dailyTotals[$balanceDate]['debit'] ?? 0;
                $dayCreditCents = $dailyTotals[$balanceDate]['credit'] ?? 0;
                $dayOpening = $openingBalance === null ? null : $this->formatCents($runningCents);
                $runningCents += $dayCreditCents - $dayDebitCents;
                $dayDebit = $this->formatCents($dayDebitCents);
                $dayCredit = $this->formatCents($dayCreditCents);
                $dayClosing = $this->formatCents($runningCents);
                $balDedupeKey = ($accountNumber ?? '') . '|' . $balanceDate . '|FULL|' . $dayOpening . '|' . $dayDebit . '|' . $dayCredit . '|' . $dayClosing;
                $daily_balances[] = [
                    'account_number' => $accountNumber,
                    'statement_date' => $balanceDate,
                    'currency' => 'RUR',
                    'opening_balance' => $dayOpening,
                    'debit_turnover' => $dayDebit,
                    'credit_turnover' => $dayCredit,
                    'closing_balance' => $dayClosing,
                    'dedupe_hash' => hash('sha256', $balDedupeKey),
                ];
            }
        }

        return [
            'account_number' => $accountNumber,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'transactions' => $transactions,
            'daily_balances' => $daily_balances,
        ];
    }

    private function formatCents(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    private function parseVtbNumericStatement(array $cells, ?string $accountNumber, ?string $periodFrom, ?string $periodTo): ?array
    {
        $transactions = [];
        $dailyBalances = [];

        $row4 = $cells[4] ?? [];
        $row4B = $row4['B'] ?? '';
        $row4D = $row4['D'] ?? '';

        $openingBalance = $this->parseAmount($row4B);
        $closingBalance = $this->parseAmount($row4D);

        $hasRow4Data = ($openingBalance !== '0.00' || $closingBalance !== '0.00');
        $hasDataRows = false;
        foreach ($cells as $rowIdx => $row) {
            if ($rowIdx < 8) { continue; }
            $hRaw = $row['H'] ?? '';
            $iRaw = $row['I'] ?? '';
            if ($hRaw !== '' || $iRaw !== '') { $hasDataRows = true; break; }
        }

        if (!$hasRow4Data && !$hasDataRows) {
            return null;
        }

        $computedDebitCents = 0;
        $computedCreditCents = 0;

        $dataStart = 8;
        $dataEnd = 0;
        foreach ($cells as $rowIdx => $row) {
            if ($rowIdx >= $dataStart) {
                $dataEnd = max($dataEnd, $rowIdx);
            }
        }

        $hasAny = false;
        foreach ($cells as $rowIdx => $row) {
            if ($rowIdx < $dataStart) {
                continue;
            }

            $hRaw = $row['H'] ?? '';
            $iRaw = $row['I'] ?? '';

            $hVal = $this->parseAmount($hRaw);
            $iVal = $this->parseAmount($iRaw);

            if ($hVal === '0.00' && $iVal === '0.00') {
                continue;
            }

            $hasAny = true;

            $debitAmount = '0.00';
            $creditAmount = '0.00';

            if ($hVal > 0) {
                $debitAmount = $hVal;
                $computedDebitCents += (int)str_replace('.', '', $hVal);
            }

            if ($iVal > 0) {
                $creditAmount = $iVal;
                $computedCreditCents += (int)str_replace('.', '', $iVal);
            }

            if ($debitAmount === '0.00' && $creditAmount === '0.00') {
                continue;
            }

            $txDate = $periodTo ?? date('Y-m-d');
            $docNum = '';
            $dedupeKey = ($accountNumber ?? '') . '|' . $txDate . '|' . $rowIdx . '|' . $debitAmount . '|' . $creditAmount;
            $dedupeHash = hash('sha256', $dedupeKey);

            $transactions[] = [
                'account_number' => $accountNumber,
                'operation_date' => $txDate,
                'document_number' => (string)$rowIdx,
                'operation_type' => '',
                'counterparty_name' => '',
                'counterparty_inn' => '',
                'counterparty_bank_bik' => '',
                'counterparty_account' => '',
                'debit_amount' => $debitAmount,
                'credit_amount' => $creditAmount,
                'purpose' => '',
                'dedupe_hash' => $dedupeHash,
            ];
        }

        if (!$hasAny) {
            return null;
        }

        $openCentsVtb = (int)str_replace('.', '', $openingBalance ?? '0.00');
        $closeCentsVtb = (int)str_replace('.', '', $closingBalance ?? '0.00');
        $calcCloseCentsVtb = $openCentsVtb + $computedCreditCents - $computedDebitCents;
        $calculatedClosing = sprintf('%d.%02d', intdiv(abs($calcCloseCentsVtb), 100), abs($calcCloseCentsVtb) % 100);
        if ($calcCloseCentsVtb < 0) { $calculatedClosing = '-' . $calculatedClosing; }

        if ($closingBalance !== '0.00') {
            if (abs($calcCloseCentsVtb - $closeCentsVtb) > 1) {
                $computedDebitCents = $computedDebitCents;
                $computedCreditCents = $computedCreditCents;
            } else {
                $closingBalance = $calculatedClosing;
            }
        } else {
            $closingBalance = $calculatedClosing;
        }

        $balanceDate = $periodTo ?? date('Y-m-d');
        $computedDebitStr = sprintf('%d.%02d', intdiv($computedDebitCents, 100), $computedDebitCents % 100);
        $computedCreditStr = sprintf('%d.%02d', intdiv($computedCreditCents, 100), $computedCreditCents % 100);
        $balanceDedupeKey = ($accountNumber ?? '') . '|' . $balanceDate . '|FULL|' . ($openingBalance ?? '0.00') . '|' . $computedDebitStr . '|' . $computedCreditStr . '|' . $closingBalance;
        $balanceDedupeHash = hash('sha256', $balanceDedupeKey);

        $dailyBalances[] = [
            'account_number' => $accountNumber,
            'statement_date' => $balanceDate,
            'currency' => 'RUR',
            'opening_balance' => number_format($opening, 2, '.', ''),
            'debit_turnover' => number_format($computedDebit, 2, '.', ''),
            'credit_turnover' => number_format($computedCredit, 2, '.', ''),
            'closing_balance' => number_format($closingBalance, 2, '.', ''),
            'dedupe_hash' => $balanceDedupeHash,
        ];

        return [
            'account_number' => $accountNumber,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'transactions' => $transactions,
            'daily_balances' => $dailyBalances,
        ];
    }

    private function columnToIndex(string $colRef): ?int
    {
        $col = preg_replace('/[0-9]/', '', $colRef);
        if ($col === '') {
            return null;
        }

        $result = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $result = $result * 26 + (ord($col[$i]) - 64);
        }
        return $result - 1;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $value;
        }

        if (is_numeric($value)) {
            $excelEpoch = 25569;
            $unixTs = ($value - $excelEpoch) * 86400;
            return date('Y-m-d', (int)$unixTs);
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    private function parseAmount(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === null) {
            return '0.00';
        }
        $value = str_replace([' ', "\xC2\xA0"], '', $value);
        $value = str_replace(',', '.', $value);
        $negative = false;
        if (strpos($value, '(') === 0 && strpos($value, ')') === strlen($value) - 1) {
            $negative = true;
            $value = substr($value, 1, -1);
        }
        if (strpos($value, '-') === 0) {
            $negative = true;
            $value = substr($value, 1);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return '0.00';
        }
        if (strpos($value, '.') === false) {
            $value .= '.00';
        } elseif (strlen(explode('.', $value)[1]) === 1) {
            $value .= '0';
        }
        if ($negative) {
            $value = '-' . $value;
        }
        return $value;
    }
}
