<?php
declare(strict_types=1);

$database = (string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || $database === ''
    || !in_array($database, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Isolated test database required');
}
$pdo = new PDO(
    'mysql:host='.(getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306').';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$pdo->exec('CREATE TABLE waste_trip_requests (
    id BIGINT UNSIGNED PRIMARY KEY,
    waste_trip_id BIGINT UNSIGNED NOT NULL,
    feo_request_id BIGINT UNSIGNED NOT NULL,
    snapshot_mass_kg DECIMAL(18,3) NOT NULL,
    position_no INT NOT NULL,
    UNIQUE KEY uk_waste_trip_request (waste_trip_id, feo_request_id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE waste_trip_registration_journal (
    id BIGINT UNSIGNED PRIMARY KEY,
    waste_trip_request_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_registration_journal_request FOREIGN KEY (waste_trip_request_id)
        REFERENCES waste_trip_requests(id) ON DELETE RESTRICT
) ENGINE=InnoDB');
$pdo->exec('INSERT INTO waste_trip_requests VALUES (10, 256, 1001, 1234.000, 0)');
$pdo->exec('INSERT INTO waste_trip_registration_journal VALUES (20, 10)');
$before = $pdo->query('SELECT id,waste_trip_id,feo_request_id,snapshot_mass_kg,position_no FROM waste_trip_requests')->fetch(PDO::FETCH_ASSOC);
$sql = (string)file_get_contents(dirname(__DIR__).'/database/migrations-local/158_waste_trip_request_archival.sql');
$pdo->exec($sql);
$after = $pdo->query('SELECT id,waste_trip_id,feo_request_id,snapshot_mass_kg,position_no,removed_at FROM waste_trip_requests')->fetch(PDO::FETCH_ASSOC);
if ($before !== array_diff_key($after, ['removed_at'=>true]) || $after['removed_at'] !== null) {
    throw new RuntimeException('Migration changed existing request composition');
}
$pdo->exec('UPDATE waste_trip_requests SET removed_at=NOW() WHERE id=10');
if ((int)$pdo->query('SELECT waste_trip_request_id FROM waste_trip_registration_journal WHERE id=20')->fetchColumn() !== 10) {
    throw new RuntimeException('Archiving broke the registration journal reference');
}
echo "WASTE_TRIP_REQUEST_ARCHIVAL_158_MYSQL=PASS\n";
