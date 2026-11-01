<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Support/helpers.php';
require_once __DIR__ . '/../app/Support/environment.php';
require_once __DIR__ . '/../app/Support/crypto_helper.php';
require_once __DIR__ . '/../app/Support/company_database.php';
require_once __DIR__ . '/../app/Core/Database.php';

loadEnvFileNonOverwriting(dirname(__DIR__) . '/.env');
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$apply = in_array('--apply', $argv, true);
$sql = file_get_contents(dirname(__DIR__) . '/database/migrations-local/057_create_crew_drivers.sql');
if ($sql === false) {
    throw new RuntimeException('Migration 057 not found.');
}

$central = new App\Core\Database($config['database']);
$pdo = $central->connection();
$companies = $pdo->query("SELECT id,name,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE db_identifier IS NOT NULL AND db_identifier <> '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$result = ['mode'=>$apply?'apply':'dry-run','companies'=>count($companies),'ok'=>[],'errors'=>[]];

foreach ($companies as $company) {
    try {
        $local = new App\Core\Database(companyDatabaseConfig($config, $company));
        $lpdo = $local->connection();
        $exists = (int)$lpdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crews'")->fetchColumn();
        if ($exists === 0) {
            $result['ok'][] = ['company_id'=>(int)$company['id'],'db'=>$company['db_identifier'],'action'=>'skip_no_crews'];
            continue;
        }
        if ($apply) {
            $lpdo->exec($sql);
        }
        $tableExists = (int)$lpdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crew_drivers'")->fetchColumn();
        $legacyCount = (int)$lpdo->query("SELECT COUNT(*) FROM crews WHERE driver_id IS NOT NULL AND driver_id>0")->fetchColumn();
        $memberCount = $tableExists ? (int)$lpdo->query("SELECT COUNT(*) FROM crew_drivers")->fetchColumn() : 0;
        $result['ok'][] = ['company_id'=>(int)$company['id'],'db'=>$company['db_identifier'],'table_exists'=>(bool)$tableExists,'legacy_crews'=>$legacyCount,'crew_driver_rows'=>$memberCount];
        if ($apply && !$tableExists) {
            throw new RuntimeException('crew_drivers table verification failed');
        }
    } catch (Throwable $e) {
        $result['errors'][] = ['company_id'=>(int)$company['id'],'db'=>$company['db_identifier'],'error'=>$e->getMessage()];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
if ($result['errors']) exit(1);
