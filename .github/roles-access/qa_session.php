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
require_once $root . '/app/Service/IdentityNormalizer.php';
require_once $root . '/app/Service/AccountAuthenticationService.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$config = require $root . '/bootstrap/app.php';
$pdo = (new App\Core\Database($config['database']))->connection();

if ($mode === 'diagnose-credentials') {
    $encoded = trim((string) stream_get_contents(STDIN));
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) { fwrite(STDERR, "invalid credential payload encoding\n"); exit(1); }
    try { $candidates = json_decode($decoded, true, 64, JSON_THROW_ON_ERROR); }
    catch (Throwable) { fwrite(STDERR, "invalid credential payload json\n"); exit(1); }
    if (!is_array($candidates) || $candidates === [] || count($candidates) > 20) {
        fwrite(STDERR, "credential candidate list invalid\n"); exit(1);
    }

    $auth = new App\Service\AccountAuthenticationService($pdo);
    $results = [];
    $selected = null;
    foreach (array_values($candidates) as $index => $candidate) {
        $login = is_array($candidate) ? trim((string) ($candidate['username'] ?? '')) : '';
        $password = is_array($candidate) ? (string) ($candidate['password'] ?? '') : '';
        $authenticated = false;
        $selectedCompanyId = null;
        $selectedCompanyReady = false;
        if ($login !== '' && $password !== '') {
            try {
                $resolved = $auth->authenticate($login, $password);
                $authenticated = is_array($resolved) && ($resolved['memberships'] ?? []) !== [];
                if ($authenticated) {
                    $first = $resolved['memberships'][0] ?? null;
                    $selectedCompanyId = is_array($first) ? (int) ($first['company_id'] ?? 0) : null;
                    if (($selectedCompanyId ?? 0) > 0) {
                        $ready = $pdo->prepare(
                            "SELECT 1 FROM company_access_readiness
                             WHERE company_id=? AND state='ready' AND verified_at IS NOT NULL AND activated_at IS NOT NULL
                             LIMIT 1"
                        );
                        $ready->execute([$selectedCompanyId]);
                        $selectedCompanyReady = (bool) $ready->fetchColumn();
                    }
                }
            } catch (Throwable) {
                $authenticated = false;
                $selectedCompanyId = null;
                $selectedCompanyReady = false;
            }
        }
        $usable = $authenticated && $selectedCompanyReady;
        if ($usable && $selected === null) { $selected = $index; }
        $results[] = [
            'index' => $index,
            'authenticated' => $authenticated,
            'ready_company' => $selectedCompanyReady,
            'usable' => $usable,
            'company_id' => $selectedCompanyId,
        ];
    }
    echo 'ROLES_QA_CREDENTIAL_DIAGNOSTIC=' . json_encode([
        'candidate_count' => count($results),
        'selected_index' => $selected,
        'results' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$stmt = $pdo->query(
    "SELECT m.legacy_source_id legacy_user_id,a.full_name,c.id company_id,c.name company_name,c.*
     FROM company_memberships m
     JOIN user_accounts a ON a.id=m.account_id
     JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
     JOIN companies c ON c.id=m.company_id
     JOIN company_access_readiness ar ON ar.company_id=m.company_id
     WHERE m.status='active' AND a.status='active' AND c.status='active'
       AND m.legacy_source='central_company_user' AND m.legacy_source_id IS NOT NULL
       AND r.system_key='company_owner' AND ar.state='ready'
       AND ar.verified_at IS NOT NULL AND ar.activated_at IS NOT NULL
     ORDER BY c.id,m.id"
);
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($owners === []) { fwrite(STDERR, "no ready owner legacy provenance\n"); exit(1); }
if (!in_array($mode, ['create-legacy-owner','create-editable-waste-owner','create-invalid-owner'], true)) { fwrite(STDERR, "unknown mode\n"); exit(1); }
$owner = $owners[0];
if ($mode === 'create-editable-waste-owner') {
    $owner = null;
    $seenCompanies = [];
    foreach ($owners as $candidate) {
        $companyId = (int) ($candidate['company_id'] ?? $candidate['id'] ?? 0);
        if ($companyId <= 0 || isset($seenCompanies[$companyId])) continue;
        $seenCompanies[$companyId] = true;
        try {
            $local = (new App\Core\Database(companyDatabaseConfig($config, $candidate)))->connection();
            $table = $local->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips'");
            if ((int) $table->fetchColumn() !== 1) continue;
            $count = (int) $local->query("SELECT COUNT(*) FROM waste_trips WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
            if ($count > 0) {
                $candidate['company_id'] = $companyId;
                $owner = $candidate;
                break;
            }
        } catch (Throwable) {
            continue;
        }
    }
    if (!$owner) { fwrite(STDERR, "no ready owner company with editable waste trip\n"); exit(1); }
}

$sid = bin2hex(random_bytes(24));
$csrf = bin2hex(random_bytes(32));
session_id($sid); session_start();
$_SESSION = [
    // Intentionally pre-activation shape: NO account_id.
    'user_id' => $mode === 'create-invalid-owner' ? 2147483647 : (int) $owner['legacy_user_id'],
    'role_code' => 'company_owner',
    'user_name' => (string) $owner['full_name'],
    'company_id' => (int) $owner['company_id'],
    'company_name' => (string) ($owner['company_name'] ?? $owner['name'] ?? ''),
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
