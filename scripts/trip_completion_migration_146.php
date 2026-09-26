<?php
declare(strict_types=1);

// Controlled production entry point. Inspection is read-only by default.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$apply = getenv('TRIP_COMPLETION_MIGRATION_MODE') === 'apply';
if ($apply && getenv('TRIP_COMPLETION_MIGRATION_CONFIRM') !== 'APPLY_TRIP_COMPLETION_146') throw new RuntimeException('Explicit migration confirmation missing');
$name = '146_remap_trip_completion_invented_source.sql';
$path = getenv('PLANEX_TENANT_MIGRATION_PATH') ?: $root.'/database/migrations-local/'.$name;
$sql = file_get_contents($path);
if ($sql === false) throw new RuntimeException('Migration SQL missing');
$checksum = hash('sha256', $sql);
$statement = trim((string)preg_replace('/^\s*--[^\n]*$/m', '', $sql));
if (substr_count($statement, ';') !== 1 || !preg_match('/^UPDATE waste_trip_completion_items\s+SET\s+recovery_source\s*=\s*\x27driver\x27/s', $statement)) throw new RuntimeException('Migration SQL outside allowed scope');

$config = require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$connect = static fn(array $c): PDO => new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$central = $connect($config['database']);
$companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll();
if (!$companies) throw new RuntimeException('No active tenants');
$snapshot = ['tenants'=>[]];
foreach ($companies as $company) {
    $pdo = $connect(companyDatabaseConfig($config, $company));
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database !== (string)$company['db_identifier']) throw new RuntimeException('Wrong tenant');
    $lock = 'planex_migrate_'.substr(hash('sha256', $database), 0, 32);
    $query = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $query->execute([$lock]);
    if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Migration lock unavailable');
    try {
        $prerequisite = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $prerequisite->execute(['144_trip_completion_explicit_statuses.sql']);
        if ($prerequisite->fetchColumn() === false) throw new RuntimeException('Migration 144 prerequisite missing');
        $prerequisite->execute([$name]);
        $stored = $prerequisite->fetchColumn();
        if ($stored !== false && !hash_equals((string)$stored, $checksum)) throw new RuntimeException('Migration 146 checksum mismatch');
        if ($stored === false && (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE CAST(LEFT(migration,3) AS UNSIGNED)>146")->fetchColumn() > 0) throw new RuntimeException('Newer migration exists');
        $rows = $pdo->query('SELECT * FROM waste_trip_completion_items ORDER BY waste_trip_request_id')->fetchAll();
        $affected = count(array_filter($rows, static fn(array $row): bool => $row['recovery_source'] === 'invented' || (int)$row['data_invented'] === 1));
        if ($stored !== false && $affected !== 0) throw new RuntimeException('Journaled migration still has invented values');
        $sourceHash = static function(PDO $db, string $table): string {
            $ctx = hash_init('sha256');
            foreach ($db->query('SELECT * FROM '.$table.' ORDER BY id') as $row) hash_update($ctx, json_encode($row, JSON_THROW_ON_ERROR)."\n");
            return hash_final($ctx);
        };
        $tripHash = $sourceHash($pdo, 'waste_trips');
        $requestHash = $sourceHash($pdo, 'waste_trip_requests');
        $documentHash = $sourceHash($pdo, 'waste_trip_completion_documents');
        if ($apply && $stored === false) {
            $pdo->beginTransaction();
            try {
                $before = $pdo->query('SELECT * FROM waste_trip_completion_items ORDER BY waste_trip_request_id FOR UPDATE')->fetchAll();
                $pdo->exec($statement);
                $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name, $checksum]);
                $after = $pdo->query('SELECT * FROM waste_trip_completion_items ORDER BY waste_trip_request_id')->fetchAll();
                if (count($before) !== count($after)) throw new RuntimeException('Completion row count changed');
                foreach ($before as $index => $original) {
                    if ($original['recovery_source'] === 'invented' || (int)$original['data_invented'] === 1) {
                        $original['recovery_source'] = 'driver';
                        $original['data_from_driver'] = 1;
                        $original['data_from_tracker'] = 0;
                        $original['data_invented'] = 0;
                        // MySQL also advances updated_at for affected rows.
                        $original['updated_at'] = $after[$index]['updated_at'];
                    }
                    if ($original != $after[$index]) throw new RuntimeException('Unexpected completion data change');
                }
                $pdo->commit();
                $stored = $checksum;
            } catch (Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        }
        $remaining = (int)$pdo->query("SELECT COUNT(*) FROM waste_trip_completion_items WHERE recovery_source='invented' OR data_invented=1")->fetchColumn();
        if ($stored !== false && $remaining !== 0) throw new RuntimeException('Invented values remain');
        if ($tripHash !== $sourceHash($pdo, 'waste_trips') || $requestHash !== $sourceHash($pdo, 'waste_trip_requests') || $documentHash !== $sourceHash($pdo, 'waste_trip_completion_documents')) throw new RuntimeException('Source data changed');
        $snapshot['tenants'][] = ['company_id'=>(int)$company['id'], 'migration_146'=>$stored !== false ? 1 : 0, 'checksum'=>$stored !== false ? $stored : null, 'completion_rows'=>count($rows), 'invented_before'=>$affected, 'invented_remaining'=>$remaining, 'trip_sha256'=>$tripHash, 'request_sha256'=>$requestHash, 'document_sha256'=>$documentHash];
    } finally {
        $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$lock]);
    }
}
echo 'TRIP_COMPLETION_146_SNAPSHOT='.json_encode($snapshot, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $apply ? "TRIP_COMPLETION_146_APPLY_OK\n" : "TRIP_COMPLETION_146_READ_ONLY_OK\n";
