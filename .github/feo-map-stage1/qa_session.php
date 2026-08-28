<?php

declare(strict_types=1);

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
if ($root === '' || !is_file($root . '/.env')) { fwrite(STDERR, "PLANEX_ROOT invalid\n"); exit(1); }
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');

$sessionName = trim((string)getenv('SESSION_COOKIE_NAME'));
if ($sessionName !== '') session_name($sessionName);
$sessionSavePath = trim((string)getenv('SESSION_SAVE_PATH'));
if ($sessionSavePath !== '') session_save_path($sessionSavePath);
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '1');

$mode = $argv[1] ?? 'create';
if ($mode === 'create') {
    $sid = bin2hex(random_bytes(24));
    $csrf = bin2hex(random_bytes(32));
    session_id($sid);
    session_start();
    $_SESSION = [
        'user_id' => 999999997,
        'user_name' => 'FEO Map Production QA',
        'user_email' => 'feo-map-qa@localhost',
        'role_code' => 'logist',
        'company_id' => 25,
        '_csrf_token' => $csrf,
    ];
    session_write_close();
    echo 'QA_SESSION=' . json_encode(['cookie'=>session_name(),'sid'=>$sid,'csrf'=>$csrf], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
if ($mode === 'delete') {
    $sid = (string)($argv[2] ?? '');
    if ($sid === '' || !preg_match('/^[a-f0-9]{48}$/D', $sid)) { fwrite(STDERR, "invalid sid\n"); exit(1); }
    session_id($sid);
    session_start();
    $_SESSION = [];
    session_destroy();
    echo "QA_SESSION_DELETED\n";
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(1);
