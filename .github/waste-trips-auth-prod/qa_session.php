<?php

declare(strict_types=1);

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "PLANEX_ROOT invalid\n");
    exit(1);
}

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$sessionName = trim((string)getenv('SESSION_COOKIE_NAME'));
if ($sessionName !== '') session_name($sessionName);
$sessionSavePath = trim((string)getenv('SESSION_SAVE_PATH'));
if ($sessionSavePath !== '') session_save_path($sessionSavePath);
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '1');

$mode = $argv[1] ?? 'create';
if ($mode === 'create') {
    $role = (string)($argv[2] ?? 'logist');
    if (!in_array($role, ['logist', 'senior_logist', 'company_owner'], true)) {
        fwrite(STDERR, "invalid role\n");
        exit(1);
    }

    $centralCfg = $config['database'];
    $central = new PDO(
        'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
        $centralCfg['username'],
        $centralCfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $central->exec('SET SESSION TRANSACTION READ ONLY');
    $central->exec('START TRANSACTION READ ONLY');
    $stmt = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1");
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($company === null) {
        fwrite(STDERR, "PLANEX company unavailable\n");
        exit(1);
    }
    $tenantCfg = companyDatabaseConfig($config, $company);
    $tenant = new PDO(
        'mysql:host=' . $tenantCfg['host'] . ';port=' . ($tenantCfg['port'] ?? 3306) . ';dbname=' . $tenantCfg['database'] . ';charset=utf8mb4',
        $tenantCfg['username'],
        $tenantCfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $tenant->exec('SET SESSION TRANSACTION READ ONLY');
    $tenant->exec('START TRANSACTION READ ONLY');
    $userId = (int)$tenant->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if ($userId <= 0) {
        fwrite(STDERR, "tenant user unavailable\n");
        exit(1);
    }

    $sid = bin2hex(random_bytes(24));
    $csrf = bin2hex(random_bytes(32));
    session_id($sid);
    session_start();
    $_SESSION = [
        'user_id' => $userId,
        'user_name' => 'Waste Trips Authenticated Production QA',
        'user_email' => 'waste-trips-auth-prod-qa@localhost',
        'role_code' => $role,
        'company_id' => 25,
        '_csrf_token' => $csrf,
    ];
    session_write_close();
    echo 'QA_SESSION=' . json_encode([
        'cookie' => session_name(),
        'sid' => $sid,
        'csrf' => $csrf,
        'role' => $role,
        'user_id' => $userId,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($mode === 'delete') {
    $sid = (string)($argv[2] ?? '');
    if ($sid === '' || !preg_match('/^[a-f0-9]{48}$/D', $sid)) {
        fwrite(STDERR, "invalid sid\n");
        exit(1);
    }
    session_id($sid);
    session_start();
    $_SESSION = [];
    session_destroy();
    echo "QA_SESSION_DELETED\n";
    exit(0);
}

fwrite(STDERR, "unknown mode\n");
exit(1);
