<?php

declare(strict_types=1);

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ''), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "PLANEX_ROOT invalid\n"); exit(1);
}
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$sessionName = trim((string) getenv('SESSION_COOKIE_NAME')); if ($sessionName !== '') session_name($sessionName);
$sessionSavePath = trim((string) getenv('SESSION_SAVE_PATH')); if ($sessionSavePath !== '') session_save_path($sessionSavePath);
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '1');

$mode = (string) ($argv[1] ?? 'create-legacy-owner');
if ($mode === 'delete') {
    $sid = (string) ($argv[2] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/D', $sid)) { fwrite(STDERR, "invalid sid\n"); exit(1); }
    session_id($sid); session_start(); $_SESSION = []; session_destroy();
    echo "ROLES_QA_SESSION_DELETED\n"; exit(0);
}

require_once $root . '/app/Core/Database.php';
$config = require $root . '/bootstrap/app.php';
$pdo = (new App\Core\Database($config['database']))->connection();
$stmt = $pdo->query(
    "SELECT m.legacy_source_id legacy_user_id,a.full_name,c.id company_id,c.name company_name
     FROM company_memberships m
     JOIN user_accounts a ON a.id=m.account_id
     JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
     JOIN companies c ON c.id=m.company_id
     JOIN company_access_readiness ar ON ar.company_id=m.company_id
     WHERE m.status='active' AND a.status='active' AND c.status='active'
       AND m.legacy_source='central_company_user' AND m.legacy_source_id IS NOT NULL
       AND r.system_key='company_owner' AND ar.state='ready'
       AND ar.verified_at IS NOT NULL AND ar.activated_at IS NOT NULL
     ORDER BY c.id,m.id LIMIT 1"
);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$owner) { fwrite(STDERR, "no ready owner legacy provenance\n"); exit(1); }
if (!in_array($mode, ['create-legacy-owner','create-invalid-owner'], true)) { fwrite(STDERR, "unknown mode\n"); exit(1); }

$sid = bin2hex(random_bytes(24));
$csrf = bin2hex(random_bytes(32));
session_id($sid); session_start();
$_SESSION = [
    // Intentionally pre-activation shape: NO account_id.
    'user_id' => $mode === 'create-invalid-owner' ? 2147483647 : (int) $owner['legacy_user_id'],
    'role_code' => 'company_owner',
    'user_name' => (string) $owner['full_name'],
    'company_id' => (int) $owner['company_id'],
    'company_name' => (string) $owner['company_name'],
    '_csrf_token' => $csrf,
    'user' => [
        'user_id' => $mode === 'create-invalid-owner' ? 2147483647 : (int) $owner['legacy_user_id'],
        'role_code' => 'company_owner',
        'company_id' => (int) $owner['company_id'],
    ],
];
session_write_close();

echo 'ROLES_QA_SESSION=' . json_encode([
    'cookie' => session_name(),
    'sid' => $sid,
    'company_id' => (int) $owner['company_id'],
    'mode' => $mode,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
