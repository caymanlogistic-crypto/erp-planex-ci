<?php

verifyCsrfRequest();

$companyId = (int) (getSessionCompanyId() ?? 0);
if ($companyId <= 0) {
    $_SESSION['flash_error'] = 'Компания не найдена.';
    redirect_to('/company/finance/bank-accounts');
}

try {
    $centralPdo = $db->connection();
    $stmt = $centralPdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company || ($company['status'] ?? '') !== 'active') {
        $_SESSION['flash_error'] = 'Компания недоступна.';
        redirect_to('/company/finance/bank-accounts');
    }

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();

    $settingsRows = \App\Service\BankStatementSettingsService::getActiveSettings($localPdo);
    $importers = $settingsRows !== [] ? $settingsRows : [null];

    $totals = [
        'transactions' => 0,
        'balances' => 0,
        'imported_files' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    $walkResult = static function (array $item) use (&$totals, &$walkResult): void {
        $status = (string) ($item['status'] ?? '');

        if ($status === 'imported') {
            $totals['imported_files']++;
            $totals['transactions'] += (int) ($item['transactions_imported'] ?? 0);
            $totals['balances'] += (int) ($item['balances_imported'] ?? 0);
        } elseif ($status === 'skipped' || $status === 'info') {
            $totals['skipped']++;
        } elseif ($status === 'error') {
            $totals['errors']++;
        }

        foreach (($item['attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $walkResult($attachment);
            }
        }
    };

    foreach ($importers as $settings) {
        try {
            $results = (new \App\Service\HardenedBankStatementImapImporter($settings))->importAllRecent($localPdo);
            foreach ($results as $result) {
                if (is_array($result)) {
                    $walkResult($result);
                }
            }
        } catch (\Throwable $importError) {
            $totals['errors']++;
            error_log('Manual bank statement IMAP refresh failed: ' . $importError->getMessage());
        }
    }

    if ($totals['imported_files'] > 0) {
        $_SESSION['flash_success'] = sprintf(
            'Обновление завершено: новых выписок — %d, операций — %d, остатков — %d, пропущено — %d.',
            $totals['imported_files'],
            $totals['transactions'],
            $totals['balances'],
            $totals['skipped']
        );
    } elseif ($totals['errors'] > 0) {
        $_SESSION['flash_error'] = 'Не удалось обновить выписки из почты. Проверьте настройки почты и повторите попытку.';
    } else {
        $_SESSION['flash_info'] = 'Новых выписок не найдено.';
    }
} catch (\Throwable $e) {
    error_log('Manual bank statement refresh error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Не удалось обновить выписки из почты.';
}

redirect_to('/company/finance/bank-accounts');
