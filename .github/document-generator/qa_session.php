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
require_once $root . '/app/Core/Database.php';
$config = require $root . '/bootstrap/app.php';

$sessionName = trim((string)getenv('SESSION_COOKIE_NAME'));
if ($sessionName !== '') session_name($sessionName);
$sessionSavePath = trim((string)getenv('SESSION_SAVE_PATH'));
if ($sessionSavePath !== '') session_save_path($sessionSavePath);
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '1');

$mode = (string)($argv[1] ?? 'create');
if ($mode === 'create') {
    $companyId = (int)(getenv('QA_COMPANY_ID') ?: 25);
    if ($companyId <= 0) {
        fwrite(STDERR, "invalid QA company\n");
        exit(1);
    }

    $pdo = (new App\Core\Database($config['database']))->connection();
    $stmt = $pdo->prepare(
        "SELECT m.id AS membership_id,m.account_id,a.full_name,c.name AS company_name
           FROM company_memberships m
           JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
           JOIN user_accounts a ON a.id=m.account_id
           JOIN companies c ON c.id=m.company_id
          WHERE m.company_id=?
            AND m.status='active'
            AND a.status='active'
            AND c.status='active'
            AND r.system_key='company_owner'
          ORDER BY m.id ASC
          LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$owner || (int)$owner['account_id'] <= 0 || (int)$owner['membership_id'] <= 0) {
        fwrite(STDERR, "active company owner unavailable\n");
        exit(1);
    }

    $accountId = (int)$owner['account_id'];
    $sid = bin2hex(random_bytes(24));
    $csrf = bin2hex(random_bytes(32));
    session_id($sid);
    session_start();
    $_SESSION = [
        'account_id' => $accountId,
        'user_id' => $accountId,
        'company_id' => $companyId,
        'company_name' => (string)$owner['company_name'],
        'user_name' => (string)$owner['full_name'],
        '_csrf_token' => $csrf,
        'user' => [
            'account_id' => $accountId,
            'user_id' => $accountId,
            'company_id' => $companyId,
        ],
    ];
    session_write_close();

    echo 'QA_SESSION=' . json_encode([
        'cookie' => session_name(),
        'sid' => $sid,
        'csrf' => $csrf,
        'company_id' => $companyId,
        'membership_id' => (int)$owner['membership_id'],
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
