<?php

declare(strict_types=1);

namespace App\Service {
    final class AccessControlService
    {
        public static function isEffectiveSystemOwner(): bool { return true; }
        public static function effectiveMembershipId(): int { return 11; }
    }
}

namespace {
    use App\Service\EntityResponsibilityService;

    require_once __DIR__ . '/../app/Service/EntityResponsibilityService.php';

    $dbName = (string)getenv('PLANEX_TEST_FIXED_DB');
    $allow = array_filter(explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')));
    if ($dbName === '' || !in_array($dbName, $allow, true) || getenv('APP_ENV') !== 'test') {
        throw new RuntimeException('Guarded disposable MySQL schema is required.');
    }
    $pdo = new PDO(
        'mysql:host=' . (getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306')
        . ';dbname=' . $dbName . ';charset=utf8mb4',
        (string)getenv('PLANEX_SCHEMA_DB_USER'),
        (string)getenv('PLANEX_SCHEMA_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec('CREATE TABLE users(id BIGINT PRIMARY KEY,membership_id BIGINT) ENGINE=InnoDB');
    foreach (['contractors','drivers','vehicle_units','vehicle_sets'] as $table) {
        $extra = $table === 'vehicle_sets'
            ? ',primary_vehicle_unit_id BIGINT,secondary_vehicle_unit_id BIGINT NULL' : '';
        $pdo->exec("CREATE TABLE {$table}(id BIGINT PRIMARY KEY,created_by_membership_id BIGINT{$extra}) ENGINE=InnoDB");
    }
    $pdo->exec('CREATE TABLE driver_vehicle_blocks(id BIGINT PRIMARY KEY,driver_id BIGINT,vehicle_set_id BIGINT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE crews(id BIGINT PRIMARY KEY,contractor_id BIGINT,driver_vehicle_block_id BIGINT,created_by_user_id BIGINT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE crew_drivers(id BIGINT PRIMARY KEY,crew_id BIGINT,driver_id BIGINT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE entity_responsibilities(entity_type VARCHAR(32),entity_id BIGINT,membership_id BIGINT,assigned_by_membership_id BIGINT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(entity_type,entity_id)) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users VALUES(1,11)');
    $pdo->exec('INSERT INTO contractors VALUES(1,11),(2,11)');
    $pdo->exec('INSERT INTO drivers VALUES(1,11),(2,11),(3,11)');
    $pdo->exec('INSERT INTO vehicle_units VALUES(1,11),(2,11),(3,11)');
    $pdo->exec('INSERT INTO vehicle_sets VALUES(1,11,1,2),(2,11,3,NULL)');
    $pdo->exec('INSERT INTO driver_vehicle_blocks VALUES(1,1,1),(2,3,2)');
    $pdo->exec('INSERT INTO crews VALUES(1,1,1,1),(2,2,2,1)');
    $pdo->exec('INSERT INTO crew_drivers VALUES(1,1,1),(2,1,2),(3,2,3)');

    EntityResponsibilityService::transferRouteExecutorCascade($pdo, [1,1], 12, 11);
    $rows = $pdo->query('SELECT entity_type,entity_id,membership_id FROM entity_responsibilities ORDER BY entity_type,entity_id')->fetchAll(PDO::FETCH_ASSOC);
    $actual = array_map(static fn(array $row): string => $row['entity_type'] . ':' . $row['entity_id'] . ':' . $row['membership_id'], $rows);
    $expected = ['contractor:1:12','driver:1:12','driver:2:12','route_executor:1:12','vehicle_set:1:12','vehicle_unit:1:12','vehicle_unit:2:12'];
    if ($actual !== $expected) {
        throw new RuntimeException('Executor cascade mismatch: ' . json_encode($actual));
    }

    // Failure after the crew transfer must roll the entire cascade back.
    $pdo->exec('UPDATE vehicle_sets SET primary_vehicle_unit_id=999 WHERE id=2');
    try {
        EntityResponsibilityService::transferRouteExecutorCascade($pdo, [2], 12, 11);
        throw new RuntimeException('Missing linked vehicle was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Missing linked vehicle was accepted.') throw $e;
    }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM entity_responsibilities WHERE (entity_type='route_executor' AND entity_id=2) OR (entity_type='contractor' AND entity_id=2) OR (entity_type='driver' AND entity_id=3) OR (entity_type='vehicle_set' AND entity_id=2)")->fetchColumn();
    if ($count !== 0) throw new RuntimeException('Failed cascade did not roll back.');

    echo "ROUTE_EXECUTOR_CASCADE=PASS\n";
    echo "ROUTE_EXECUTOR_CASCADE_ATOMIC=PASS\n";
}
