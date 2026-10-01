<?php

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'storage');

if (!is_dir(STORAGE_PATH) && !mkdir(STORAGE_PATH, 0750, true) && !is_dir(STORAGE_PATH)) {
    fwrite(STDERR, "Cannot create storage directory.\n");
    exit(1);
}
$lockHandle = fopen(STORAGE_PATH . DIRECTORY_SEPARATOR . 'bank_statement_import.lock', 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "Cannot open bank import lock file.\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another bank statement import is already running.\n";
    fclose($lockHandle);
    exit(0);
}
register_shutdown_function(static function () use ($lockHandle): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'helpers.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'company_database.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'core_runtime.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'crypto_helper.php';

$envFile = BASE_PATH . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

$dbConfig = require BASE_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Database.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'LocalMigrationService.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'BankStatementXlsxParser.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'BankFinanceService.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'BankStatementFileStore.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'HardenedBankStatementImapImporter.php';
require_once BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'BankStatementSettingsService.php';

$overallErrors = 0;

try {
    $db = new \App\Core\Database($dbConfig);
    $pdo = $db->connection();

    $legacyCompanyId = (int)(getenv('BANK_IMPORT_COMPANY_ID') ?: 0);

    if ($legacyCompanyId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$legacyCompanyId]);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($companies)) {
        echo "No active companies found.\n";
        exit(0);
    }

    echo "Found " . count($companies) . " active company(ies).\n";

    foreach ($companies as $company) {
        echo "\n--- Company #{$company['id']}: {$company['name']} ---\n";

        try {
            $localDbConfig = companyDatabaseConfig(['database' => $dbConfig], $company);
            $localDb = new \App\Core\Database($localDbConfig);
            $localPdo = $localDb->connection();
            applyLocalMigrations($localPdo);

            $settingsList = \App\Service\BankStatementSettingsService::getActiveSettings($localPdo);

            if (empty($settingsList)) {
                echo "  No active IMAP settings for this company.\n";
                continue;
            }

            echo "  Active IMAP setting(s): " . count($settingsList) . "\n";

            foreach ($settingsList as $setting) {
                echo "  Processing setting #{$setting['id']} ({$setting['bank_code']}: {$setting['username']})...\n";

                $importer = new \App\Service\HardenedBankStatementImapImporter($setting);

                $results = $importer->importAllRecent($localPdo, company_storage_path($company['storage_path'] ?? null, (int)$company['id']));

                $found = 0;
                $imported = 0;
                $skipped = 0;
                $errors = 0;

                foreach ($results as $r) {
                    if ($r['status'] === 'info') {
                        echo "    INFO: {$r['message']}\n";
                        continue;
                    }

                    $found++;
                    $label = $r['subject'] ?? ('msg uid=' . ($r['uid'] ?? '?'));

                    if ($r['status'] === 'error') {
                        echo "    ERROR: {$label} — {$r['message']}\n";
                        $errors++;
                        continue;
                    }

                    if ($r['status'] === 'processed' && isset($r['attachments'])) {
                        foreach ($r['attachments'] as $att) {
                            if ($att['status'] === 'imported') {
                                echo "    OK: {$label} / {$att['message']}\n";
                                $imported++;
                            } elseif ($att['status'] === 'skipped') {
                                echo "    SKIP: {$label} / {$att['message']}\n";
                                $skipped++;
                            } else {
                                echo "    ERR: {$label} / {$att['message']}\n";
                                $errors++;
                            }
                        }
                    } elseif ($r['status'] === 'skipped') {
                        echo "    SKIP: {$label} — {$r['message']}\n";
                        $skipped++;
                    }
                }

                echo "  Setting #{$setting['id']} summary: found={$found}, imported={$imported}, skipped={$skipped}, errors={$errors}\n";
                $overallErrors += $errors;
            }
        } catch (\Exception $e) {
            echo "  ERROR for company #{$company['id']}: " . $e->getMessage() . "\n";
            $overallErrors++;
        }
    }

    echo "\n=== Overall import complete ===\n";
    echo "Total errors: {$overallErrors}\n";

    if ($overallErrors > 0) {
        exit(1);
    }
} catch (\Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
