<?php

declare(strict_types=1);

namespace App\Service {
    final class AccessControlService
    {
        public static function canSeeAllCompanyData(array $user): bool
        {
            return (string) ($user['role_code'] ?? '') === 'company_owner';
        }
    }

    final class LinearRouteService
    {
        public static function isVisibleEntity(\PDO $pdo, string $table, int $entityId, array $user, string $entityType): bool
        {
            if ($table !== 'clients' || $entityId <= 0) {
                return false;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE id=? AND status='active' AND deleted_at IS NULL");
            $stmt->execute([$entityId]);
            return (int) $stmt->fetchColumn() === 1;
        }

        public static function fetchVisibleRouteExecutors(\PDO $pdo, array $user): array
        {
            return [['id' => 7, 'contractor_name' => 'Executor', 'driver_name' => 'Driver']];
        }
    }
}

namespace {
    use App\Service\WasteTripLegacyImportService;
    use App\Service\WasteTripService;

    $root = dirname(__DIR__);
    require_once $root . '/app/Service/WasteTripService.php';
    require_once $root . '/app/Service/WasteTripLegacyImportService.php';

    function failTest(string $message): never
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function must(bool $condition, string $message): void
    {
        if (!$condition) {
            failTest($message);
        }
    }

    function sameValue(mixed $actual, mixed $expected, string $message): void
    {
        if ($actual !== $expected) {
            failTest($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
        }
    }

    function nearValue(float $actual, float $expected, float $epsilon, string $message): void
    {
        if (abs($actual - $expected) > $epsilon) {
            failTest($message . " expected={$expected} actual={$actual}");
        }
    }

    function mustThrow(callable $fn, string $message): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        failTest($message);
    }

    function mysqlConfig(): array
    {
        return [
            'host' => getenv('WASTE_TRIP_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('WASTE_TRIP_TEST_DB_PORT') ?: '3306',
            'user' => getenv('WASTE_TRIP_TEST_DB_USER') ?: 'planex_ci',
            'pass' => getenv('WASTE_TRIP_TEST_DB_PASSWORD') ?: 'planex_ci',
        ];
    }

    function connectDb(array $cfg, ?string $database = null): \PDO
    {
        $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'];
        if ($database !== null) {
            $dsn .= ';dbname=' . $database;
        }
        $dsn .= ';charset=utf8mb4';
        return new \PDO($dsn, $cfg['user'], $cfg['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    function baseInput(string $status = WasteTripService::STATUS_SEARCHING): array
    {
        return [
            'client_id' => 1,
            'route_executor_id' => null,
            'responsible_user_id' => 1,
            'destination_type' => 'warehouse',
            'utilizer_id' => null,
            'status' => $status,
            'planned_start_mode' => 'exact',
            'planned_start_date' => '2026-09-10',
            'planned_start_date_from' => null,
            'planned_start_date_to' => null,
            'actual_start_date' => null,
            'actual_end_date' => null,
            'comment' => null,
            'cancellation_reason' => null,
        ];
    }

    function ownerUser(): array
    {
        return ['user_id' => 1, 'role_code' => 'company_owner'];
    }

    function logistUser(): array
    {
        return ['user_id' => 2, 'role_code' => 'logist'];
    }

    function applyWasteMigration(\PDO $pdo, string $root): void
    {
        $sql = file_get_contents($root . '/database/migrations-local/128_create_waste_trips.sql');
        if (!is_string($sql)) {
            failTest('cannot read waste migration');
        }
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    function createTenantFixture(\PDO $pdo, string $root): void
    {
        $pdo->exec("CREATE TABLE clients (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY, full_name VARCHAR(255) NOT NULL, role_code VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE crews (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE feo_utilizers (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, short_name VARCHAR(255) NULL, full_name VARCHAR(255) NULL, address VARCHAR(500) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE feo_requests (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, zayavka_id VARCHAR(64) NOT NULL UNIQUE, mass_netto DECIMAL(14,4) NULL, polnoe_naim_zakazchika VARCHAR(255) NULL, inn_zakazchika VARCHAR(32) NULL, naim_oo_gruzootpravitel VARCHAR(255) NULL, mno_adres_pogruzki VARCHAR(500) NULL, naim_otkhoda_fkko VARCHAR(255) NULL, nomer_opasnogo_gr VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE feo_placement_current (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO clients VALUES (1,'Client','active',NULL)");
        $pdo->exec("INSERT INTO users VALUES (1,'Owner','company_owner','active',NULL),(2,'Logist','logist','active',NULL)");
        $pdo->exec("INSERT INTO crews VALUES (7)");
        $pdo->exec("INSERT INTO feo_utilizers VALUES (1,'Utilizer','Utilizer','Address',1,0)");
        $stmt = $pdo->prepare("INSERT INTO feo_requests (id,zayavka_id,mass_netto,polnoe_naim_zakazchika,inn_zakazchika,naim_oo_gruzootpravitel,mno_adres_pogruzki) VALUES (?,?,?,?,?,?,?)");
        $placement = $pdo->prepare("INSERT INTO feo_placement_current (zayavka_id) VALUES (?)");
        $n = 100;
        foreach (['R100','R101','R102','R103','R104','R105','R106','R107','R108','R109'] as $id) {
            $n++;
            $mass = match ($id) {
                'R100' => '1.5000',
                'R101' => '2.0000',
                'R105' => '3.2500',
                default => '1.0000',
            };
            $stmt->execute([$n, $id, $mass, 'Customer', '7700000000', 'Sender', 'Load address']);
            $placement->execute([$id]);
        }
        applyWasteMigration($pdo, $root);
    }

    function createLegacyFixture(\PDO $pdo, string $requestId): void
    {
        $pdo->exec("CREATE TABLE flights (
            id INT NOT NULL PRIMARY KEY,
            block_date DATETIME NULL,
            zayavki_ids TEXT NOT NULL,
            zayavki_count INT NOT NULL DEFAULT 0,
            total_mass_tonn DECIMAL(10,3) NULL,
            unload_type VARCHAR(20) NOT NULL,
            status VARCHAR(50) NOT NULL,
            contractor_id INT NULL,
            driver_id BIGINT UNSIGNED NULL,
            cost INT NULL,
            payment_terms VARCHAR(500) NULL,
            comment VARCHAR(500) NULL,
            planned_start_date DATE NULL,
            planned_start_date_from DATE NULL,
            planned_start_date_to DATE NULL,
            actual_start_date DATE NULL,
            actual_end_date DATE NULL,
            assigned_manager_id INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE contractors (id INT NOT NULL PRIMARY KEY, name VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE drivers (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, full_name VARCHAR(255) NULL, vehicle_make_plate VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE users (id INT NOT NULL PRIMARY KEY, `Фамилия` VARCHAR(255) NULL, `Имя` VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $pdo->prepare("INSERT INTO flights (id,block_date,zayavki_ids,zayavki_count,total_mass_tonn,unload_type,status,comment) VALUES (9001,NOW(),?,1,1.000,'OO','search','race')");
        $stmt->execute([$requestId]);
    }

    function waitForBarrier(string $barrier): void
    {
        $deadline = microtime(true) + 10.0;
        while (!is_file($barrier)) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('race barrier timeout');
            }
            usleep(20_000);
        }
    }

    function raceWorker(array $cfg): never
    {
        try {
            $pdo = connectDb($cfg, (string) $cfg['db']);
            waitForBarrier((string) $cfg['barrier']);
            if (($cfg['mode'] ?? '') === 'create') {
                $tripId = WasteTripService::create($pdo, baseInput(), [(string) $cfg['request_id']], ownerUser());
            } elseif (($cfg['mode'] ?? '') === 'legacy') {
                $legacyPdo = connectDb($cfg, (string) $cfg['legacy_db']);
                $tripId = WasteTripLegacyImportService::import($legacyPdo, $pdo, 9001, [
                    'client_id' => 1,
                    'route_executor_id' => null,
                    'responsible_user_id' => 1,
                    'destination_type' => 'warehouse',
                    'utilizer_id' => null,
                ], ownerUser());
            } else {
                throw new \RuntimeException('unknown worker mode');
            }
            echo json_encode(['success' => true, 'trip_id' => $tripId], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit(0);
    }

    function runRace(array $configs): array
    {
        $barrier = sys_get_temp_dir() . '/waste_trip_race_' . bin2hex(random_bytes(8));
        $processes = [];
        foreach ($configs as $cfg) {
            $cfg['barrier'] = $barrier;
            $payload = base64_encode(json_encode($cfg, JSON_THROW_ON_ERROR));
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' race-worker ' . escapeshellarg($payload);
            $pipes = [];
            $proc = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!is_resource($proc)) {
                failTest('cannot start race worker');
            }
            fclose($pipes[0]);
            $processes[] = [$proc, $pipes];
        }
        usleep(250_000);
        touch($barrier);
        $results = [];
        foreach ($processes as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
            if ($exitCode !== 0) {
                failTest('race worker exit=' . $exitCode . ' stderr=' . trim((string) $stderr));
            }
            $decoded = json_decode(trim((string) $stdout), true);
            if (!is_array($decoded)) {
                failTest('invalid race worker output: ' . trim((string) $stdout) . ' stderr=' . trim((string) $stderr));
            }
            $results[] = $decoded;
        }
        @unlink($barrier);
        return $results;
    }

    if (($argv[1] ?? '') === 'race-worker') {
        $payload = base64_decode((string) ($argv[2] ?? ''), true);
        $cfg = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($cfg)) {
            echo json_encode(['success' => false, 'error' => 'invalid worker config']);
            exit(0);
        }
        raceWorker($cfg);
    }

    $parsed = WasteTripService::parseRequestIds("285664, 281861; 257387\n285664");
    sameValue($parsed['ids'], ['285664','281861','257387'], 'request parser order');
    sameValue($parsed['duplicates'], ['285664'], 'request parser duplicate');
    nearValue(WasteTripService::massKg('1.2345'), 1234.5, 0.0005, 'mass kg normalization');
    sameValue(WasteTripLegacyImportService::mapLegacyStatus('search'), WasteTripService::STATUS_SEARCHING, 'legacy search mapping');
    sameValue(WasteTripLegacyImportService::mapLegacyStatus('found'), WasteTripService::STATUS_ASSIGNED, 'legacy found mapping');
    sameValue(WasteTripLegacyImportService::mapLegacyStatus('started'), WasteTripService::STATUS_IN_PROGRESS, 'legacy started mapping');
    sameValue(WasteTripLegacyImportService::mapLegacyStatus('completed'), WasteTripService::STATUS_COMPLETED, 'legacy completed mapping');
    sameValue(WasteTripLegacyImportService::mapLegacyStatus('mystery'), null, 'unknown legacy status blocks');

    $manualWarehouse = WasteTripLegacyImportService::resolveDestinationMapping('OO', ['destination_type' => 'warehouse']);
    sameValue($manualWarehouse, ['destination_type' => 'warehouse', 'utilizer_id' => null], 'OO must allow explicit warehouse mapping');
    $manualUtilizer = WasteTripLegacyImportService::resolveDestinationMapping('OO', ['destination_type' => 'utilizer', 'utilizer_id' => 1]);
    sameValue($manualUtilizer, ['destination_type' => 'utilizer', 'utilizer_id' => 1], 'OO must allow explicit utilizer mapping');
    mustThrow(static fn() => WasteTripLegacyImportService::resolveDestinationMapping('OO', []), 'OO without explicit destination must fail');
    mustThrow(static fn() => WasteTripLegacyImportService::resolveDestinationMapping('SKLAD', []), 'SKLAD without explicit destination must fail');
    WasteTripService::assertTransition(WasteTripService::STATUS_SEARCHING, WasteTripService::STATUS_ASSIGNED);
    mustThrow(static fn() => WasteTripService::assertTransition(WasteTripService::STATUS_COMPLETED, WasteTripService::STATUS_IN_PROGRESS), 'backwards transition must fail');
    echo "WASTE_TRIP_DOMAIN=PASS\n";

    $self = file_get_contents(__FILE__);
    $legacyText = file_get_contents($root . '/app/Service/WasteTripLegacyImportService.php');
    $workflowText = file_get_contents($root . '/.github/workflows/waste_trips_regression.yml');
    $importView = file_get_contents($root . '/app/View/pages/company_waste_trip_import.php');
    must(is_string($self) && str_starts_with($self, '<?php'), 'test file must begin with <?php');
    must(!str_starts_with((string) $self, 'PD9waHA'), 'test file must not be base64-wrapped');
    must(is_string($legacyText) && str_contains($legacyText, 'SET SESSION TRANSACTION READ ONLY'), 'legacy connection must remain read-only');
    must(!str_contains((string) $legacyText, "'OO' => [\n                'destination_type' => 'utilizer'"), 'OO must not hard-map to utilizer');
    must(str_contains((string) $legacyText, 'resolveDestinationMapping'), 'explicit destination mapping required');
    must(str_contains((string) $importView, 'name="destination_type"'), 'import UI must request explicit destination mapping');
    must(str_contains((string) $importView, 'не определяет ERP назначение автоматически'), 'import UI must explain no automatic OO/SKLAD mapping');
    foreach (['WASTE_TRIP_DOMAIN=PASS','WASTE_TRIP_STATIC_SECURITY=PASS','WASTE_TRIP_MYSQL=PASS','WASTE_TRIP_CONCURRENCY=PASS'] as $marker) {
        must(str_contains((string) $workflowText, $marker), 'workflow must enforce marker ' . $marker);
    }
    echo "WASTE_TRIP_STATIC_SECURITY=PASS\n";

    if (!extension_loaded('pdo_mysql')) {
        echo "WASTE_TRIP_MYSQL=REMOTE_REQUIRED\n";
        echo "WASTE_TRIP_CONCURRENCY=REMOTE_REQUIRED\n";
        echo "WASTE_TRIP_REGRESSION=PASS_WITH_REMOTE_GATE\n";
        exit(0);
    }

    $cfg = mysqlConfig();
    $suffix = strtolower(bin2hex(random_bytes(5)));
    $tenantDb = 'waste_trip_ci_' . $suffix;
    $legacyA = $tenantDb . '_la';
    $legacyB = $tenantDb . '_lb';
    $admin = connectDb($cfg);

    try {
        foreach ([$tenantDb, $legacyA, $legacyB] as $db) {
            $admin->exec("DROP DATABASE IF EXISTS `{$db}`");
            $admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $pdo = connectDb($cfg, $tenantDb);
        createTenantFixture($pdo, $root);
        $legacyPdoA = connectDb($cfg, $legacyA);
        $legacyPdoB = connectDb($cfg, $legacyB);
        createLegacyFixture($legacyPdoA, 'R106');
        createLegacyFixture($legacyPdoB, 'R107');

        $tripSnapshot = WasteTripService::create($pdo, baseInput(), ['R100'], ownerUser());
        $snapshot = (float) $pdo->query("SELECT snapshot_mass_kg FROM waste_trip_requests WHERE waste_trip_id={$tripSnapshot}")->fetchColumn();
        nearValue($snapshot, 1500.0, 0.001, 'snapshot mass at create');
        $pdo->exec("UPDATE feo_requests SET mass_netto=9.9999 WHERE zayavka_id='R100'");
        $snapshotAfter = (float) $pdo->query("SELECT snapshot_mass_kg FROM waste_trip_requests WHERE waste_trip_id={$tripSnapshot}")->fetchColumn();
        nearValue($snapshotAfter, 1500.0, 0.001, 'snapshot mass must not drift');

        $tripLifecycle = WasteTripService::create($pdo, baseInput(), ['R101'], ownerUser());
        $assigned = baseInput(WasteTripService::STATUS_ASSIGNED);
        $assigned['route_executor_id'] = 7;
        WasteTripService::update($pdo, $tripLifecycle, $assigned, ['R101'], 1, ownerUser());
        $inProgress = $assigned;
        $inProgress['status'] = WasteTripService::STATUS_IN_PROGRESS;
        $inProgress['actual_start_date'] = '2026-09-10';
        WasteTripService::update($pdo, $tripLifecycle, $inProgress, ['R101'], 2, ownerUser());
        mustThrow(static function () use ($pdo, $tripLifecycle, $inProgress): void {
            WasteTripService::update($pdo, $tripLifecycle, $inProgress, ['R101','R102'], 3, logistUser());
        }, 'in-progress composition change without owner confirmation must fail');
        sameValue((int) $pdo->query("SELECT lock_version FROM waste_trips WHERE id={$tripLifecycle}")->fetchColumn(), 3, 'failed composition update must rollback version');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id={$tripLifecycle} AND fr.zayavka_id='R102'")->fetchColumn(), 0, 'failed composition update must rollback links');
        $completed = $inProgress;
        $completed['status'] = WasteTripService::STATUS_COMPLETED;
        $completed['actual_end_date'] = '2026-09-11';
        WasteTripService::update($pdo, $tripLifecycle, $completed, ['R101'], 3, ownerUser());
        mustThrow(static function () use ($pdo, $tripLifecycle, $completed): void {
            $completed['confirm_composition_correction'] = '1';
            WasteTripService::update($pdo, $tripLifecycle, $completed, ['R101','R102'], 4, ownerUser());
        }, 'completed composition change must fail');

        $badEvidence = [
            'source_system' => WasteTripLegacyImportService::SOURCE_SYSTEM,
            'legacy_flight_id' => 7001,
            'legacy_total_mass_kg' => 1000.0,
            'legacy_cost' => null,
            'legacy_payment_terms' => '',
            'payload' => ['bad_utf8' => "\xB1\x31"],
        ];
        mustThrow(static function () use ($pdo, $badEvidence): void {
            WasteTripService::create($pdo, baseInput(), ['R103'], ownerUser(), $badEvidence);
        }, 'legacy audit serialization failure must rollback full create');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights' AND legacy_flight_id=7001")->fetchColumn(), 0, 'rollback must remove trip');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE fr.zayavka_id='R103'")->fetchColumn(), 0, 'rollback must remove request link');

        $goodEvidence = [
            'source_system' => WasteTripLegacyImportService::SOURCE_SYSTEM,
            'legacy_flight_id' => 7002,
            'legacy_total_mass_kg' => 1000.0,
            'legacy_cost' => null,
            'legacy_payment_terms' => '',
            'payload' => ['unload_type' => 'OO'],
        ];
        WasteTripService::create($pdo, baseInput(), ['R103'], ownerUser(), $goodEvidence);
        mustThrow(static function () use ($pdo, $goodEvidence): void {
            WasteTripService::create($pdo, baseInput(), ['R104'], ownerUser(), $goodEvidence);
        }, 'duplicate legacy identity must fail');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights' AND legacy_flight_id=7002")->fetchColumn(), 1, 'legacy identity must remain unique');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE fr.zayavka_id='R104'")->fetchColumn(), 0, 'failed duplicate legacy create must not claim second request');

        $tripCancel = WasteTripService::create($pdo, baseInput(), ['R108'], ownerUser());
        WasteTripService::cancel($pdo, $tripCancel, 1, 'test cancel', ownerUser());
        sameValue((string) $pdo->query("SELECT status FROM waste_trips WHERE id={$tripCancel}")->fetchColumn(), WasteTripService::STATUS_CANCELLED, 'cancel status');
        $tripReclaim = WasteTripService::create($pdo, baseInput(), ['R108'], ownerUser());
        must($tripReclaim > $tripCancel, 'cancelled claim must be reusable');

        echo "WASTE_TRIP_MYSQL=PASS\n";

        $raceCfg = $cfg + ['db' => $tenantDb, 'mode' => 'create', 'request_id' => 'R105'];
        $createRace = runRace([$raceCfg, $raceCfg]);
        $createSuccesses = array_values(array_filter($createRace, static fn(array $r): bool => !empty($r['success'])));
        sameValue(count($createSuccesses), 1, 'concurrent create same FEO request must have exactly one success');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE fr.zayavka_id='R105' AND wt.status<>'cancelled'")->fetchColumn(), 1, 'single active claim after create race');

        $legacyRaceA = $cfg + ['db' => $tenantDb, 'legacy_db' => $legacyA, 'mode' => 'legacy'];
        $legacyRaceB = $cfg + ['db' => $tenantDb, 'legacy_db' => $legacyB, 'mode' => 'legacy'];
        $legacyRace = runRace([$legacyRaceA, $legacyRaceB]);
        $legacySuccesses = array_values(array_filter($legacyRace, static fn(array $r): bool => !empty($r['success'])));
        sameValue(count($legacySuccesses), 1, 'concurrent legacy import same legacy_flight_id must have exactly one success');
        sameValue((int) $pdo->query("SELECT COUNT(*) FROM waste_trips WHERE source_system='legacy_flights' AND legacy_flight_id=9001")->fetchColumn(), 1, 'single immutable legacy identity after race');
        $raceTrip = $pdo->query("SELECT id,destination_type FROM waste_trips WHERE source_system='legacy_flights' AND legacy_flight_id=9001")->fetch();
        sameValue((string) $raceTrip['destination_type'], 'warehouse', 'legacy OO destination must follow explicit mapping, not auto utilizer');
        $auditRow = $pdo->query("SELECT legacy_payload_json,mapping_json FROM waste_trip_legacy_import_audits WHERE legacy_flight_id=9001")->fetch();
        $payload = json_decode((string) $auditRow['legacy_payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $mapping = json_decode((string) $auditRow['mapping_json'], true, 512, JSON_THROW_ON_ERROR);
        sameValue((string) ($payload['unload_type'] ?? ''), 'OO', 'legacy OO source value must be preserved in evidence');
        sameValue((string) ($mapping['destination_type'] ?? ''), 'warehouse', 'explicit ERP destination mapping must be preserved in audit');

        echo "WASTE_TRIP_CONCURRENCY=PASS\n";
        echo "WASTE_TRIP_REGRESSION=PASS\n";
    } finally {
        foreach ([$legacyA, $legacyB, $tenantDb] as $db) {
            try {
                $admin->exec("DROP DATABASE IF EXISTS `{$db}`");
            } catch (\Throwable) {
            }
        }
    }
}
