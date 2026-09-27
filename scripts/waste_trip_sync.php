<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n"); exit(1);
}
$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/WasteTripLegacySourceService.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacySyncService.php';

$legacy = \App\Service\WasteTripLegacySourceService::connectionFromEnvironment();
if (!$legacy instanceof PDO) throw new RuntimeException('Legacy flights DB is not configured');
$centralCfg = $config['database'];
$central = new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port'] ?? 3306).';dbname='.$centralCfg['database'].';charset=utf8mb4', $centralCfg['username'], $centralCfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$failedTenants = 0;
foreach ($companies as $company) {
    try {
        $cfg = companyDatabaseConfig($config, $company);
        $tenant = new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port'] ?? 3306).';dbname='.$cfg['database'].';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        if (!\App\Service\WasteTripService::legacyMetadataReady($tenant)) {
            fwrite(STDOUT, 'company='.(int)$company['id'].' skipped=schema_not_ready'.PHP_EOL);
            continue;
        }
        $result = \App\Service\WasteTripLegacySyncService::syncAll($legacy, $tenant);
        fwrite(STDOUT, 'company='.(int)$company['id'].' '.json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    } catch (Throwable $e) {
        $failedTenants++;
        fwrite(STDERR, 'company='.(int)$company['id'].' ERROR '.$e->getMessage().PHP_EOL);
    }
}
if ($failedTenants > 0) exit(2);
echo "WASTE_TRIP_SYNC_OK\n";
