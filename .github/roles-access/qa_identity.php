<?php

declare(strict_types=1);

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$runId = trim((string)(getenv('QA_RUN_ID') ?: ''));
if ($root === '' || !preg_match('/^[0-9]{1,20}$/D', $runId) || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "invalid QA identity environment\n"); exit(1);
}
$stateFile = '/tmp/roles_access_func_identity_' . $runId . '.json';
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/IdentityNormalizer.php';
require_once $root . '/app/Service/CompanyRoleService.php';
require_once $root . '/app/Service/CompanyMembershipService.php';
require_once $root . '/app/Service/EntityResponsibilityService.php';
$config = require $root . '/bootstrap/app.php';
$pdo = (new App\Core\Database($config['database']))->connection();
$mode = (string)($argv[1] ?? '');

function qaIdentityPrefix(string $runId): string
{
    return 'qa_func_' . $runId . '_';
}

function cleanupQaIdentity(PDO $pdo, string $stateFile, string $runId): void
{
    $prefix = qaIdentityPrefix($runId);
    $expectedAccountId = 0;
    $expectedMembershipId = 0;
    if (is_file($stateFile)) {
        $state = json_decode((string)file_get_contents($stateFile), true, 32, JSON_THROW_ON_ERROR);
        $expectedAccountId = (int)($state['account_id'] ?? 0);
        $expectedMembershipId = (int)($state['membership_id'] ?? 0);
        $companyId = (int)($state['company_id'] ?? 0);
        $normalized = (string)($state['normalized_login'] ?? '');
        if ($expectedAccountId <= 0 || $expectedMembershipId <= 0 || $companyId <= 0 || !str_starts_with($normalized, $prefix)) {
            throw new RuntimeException('invalid QA identity cleanup state');
        }
    }

    // Prefix is unique to this GitHub run. It lets cleanup recover even if the
    // process died after the DB insert but before the local state file was written.
    $accounts = $pdo->prepare(
        'SELECT id, normalized_login, full_name FROM user_accounts '
        . 'WHERE LEFT(normalized_login, ?) = ? ORDER BY id FOR UPDATE'
    );
    $accounts->execute([strlen($prefix), $prefix]);
    $rows = $accounts->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $accountId = (int)($row['id'] ?? 0);
        $normalized = (string)($row['normalized_login'] ?? '');
        if ($accountId <= 0 || !str_starts_with($normalized, $prefix)
            || (string)($row['full_name'] ?? '') !== 'QA Functional ' . $runId) {
            throw new RuntimeException('unexpected account inside QA identity namespace');
        }
        $memberships = $pdo->prepare(
            "SELECT m.id,m.company_id,r.system_key,m.status
               FROM company_memberships m
               JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
              WHERE m.account_id=? ORDER BY m.id"
        );
        $memberships->execute([$accountId]);
        $membershipRows = $memberships->fetchAll(PDO::FETCH_ASSOC);
        foreach ($membershipRows as $membership) {
            if ((string)($membership['system_key'] ?? '') !== App\Service\CompanyRoleService::OWNER_SYSTEM_KEY
                || (string)($membership['status'] ?? '') !== 'active') {
                throw new RuntimeException('unexpected QA membership shape during cleanup');
            }
            (new App\Service\CompanyMembershipService($pdo))->remove(
                (int)$membership['company_id'], (int)$membership['id']
            );
        }
        $delete = $pdo->prepare(
            'DELETE FROM user_accounts WHERE id=? AND normalized_login=? '
            . 'AND NOT EXISTS (SELECT 1 FROM company_memberships WHERE account_id=user_accounts.id)'
        );
        $delete->execute([$accountId, $normalized]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('QA account cleanup did not delete exactly one account');
        }
    }

    $prefixCheck = $pdo->prepare('SELECT COUNT(*) FROM user_accounts WHERE LEFT(normalized_login, ?) = ?');
    $prefixCheck->execute([strlen($prefix), $prefix]);
    if ((int)$prefixCheck->fetchColumn() !== 0) {
        throw new RuntimeException('QA account namespace residue detected');
    }
    if ($expectedMembershipId > 0) {
        foreach ([
            ['SELECT COUNT(*) FROM company_memberships WHERE id=? OR account_id=?', [$expectedMembershipId, $expectedAccountId]],
            ['SELECT COUNT(*) FROM membership_page_overrides WHERE membership_id=?', [$expectedMembershipId]],
            ['SELECT COUNT(*) FROM membership_data_scope_overrides WHERE membership_id=?', [$expectedMembershipId]],
        ] as [$sql, $params]) {
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            if ((int)$stmt->fetchColumn() !== 0) throw new RuntimeException('QA identity residue detected');
        }
    }
    if (is_file($stateFile) && !unlink($stateFile)) {
        throw new RuntimeException('QA identity state cleanup failed');
    }
    echo "QA_IDENTITY_RESIDUE=0\n";
}

if ($mode === 'cleanup') {
    cleanupQaIdentity($pdo, $stateFile, $runId);
    exit(0);
}
if ($mode !== 'create') { fwrite(STDERR, "unknown QA identity mode\n"); exit(1); }
if (is_file($stateFile)) { fwrite(STDERR, "QA identity state already exists\n"); exit(1); }

/** @param list<array{id:int,membership_id:int,full_name:string,login:string}> $recipients
 *  @param list<array{id:int,membership_id:int,full_name:string,login:string}> $dynamicRecipients */
function hasReversibleDynamicCandidate(PDO $tenant, array $recipients, array $dynamicRecipients): bool
{
    $eligibleUserIds = array_fill_keys(array_map(static fn(array $r): int => (int)$r['id'], $recipients), true);
    $dynamicUserIds = array_values(array_filter(array_map(
        static fn(array $r): int => (int)$r['id'],
        $dynamicRecipients
    ), static fn(int $id): bool => $id > 0));
    if (count($eligibleUserIds) < 2 || $dynamicUserIds === []) {
        return false;
    }
    $queries = [
        "SELECT responsible_user.id AS responsible_user_id
           FROM contractors e
      LEFT JOIN users creator_user ON e.created_by_user_id=creator_user.id
      LEFT JOIN entity_responsibilities er ON er.entity_type='contractor' AND er.entity_id=e.id
      LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id,e.created_by_membership_id,creator_user.membership_id)
          WHERE e.status!='archived'",
        "SELECT responsible_user.id AS responsible_user_id
           FROM drivers e
      LEFT JOIN users creator_user ON e.created_by_user_id=creator_user.id
      LEFT JOIN entity_responsibilities er ON er.entity_type='driver' AND er.entity_id=e.id
      LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id,e.created_by_membership_id,creator_user.membership_id)
          WHERE e.status!='archived'",
        "SELECT responsible_user.id AS responsible_user_id
           FROM vehicle_sets e
      LEFT JOIN users creator_user ON e.created_by_user_id=creator_user.id
      LEFT JOIN entity_responsibilities er ON er.entity_type='vehicle_set' AND er.entity_id=e.id
      LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id,e.created_by_membership_id,creator_user.membership_id)
          WHERE e.status!='archived'",
        "SELECT responsible_user.id AS responsible_user_id
           FROM crews e
      LEFT JOIN users creator_user ON e.created_by_user_id=creator_user.id
      LEFT JOIN entity_responsibilities er ON er.entity_type='route_executor' AND er.entity_id=e.id
      LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id,creator_user.membership_id)
          WHERE e.status!='archived'",
    ];
    foreach ($queries as $sql) {
        try {
            $currentUserIds = array_map('intval', $tenant->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            continue;
        }
        foreach ($currentUserIds as $currentUserId) {
            if ($currentUserId <= 0 || !isset($eligibleUserIds[$currentUserId])) {
                continue;
            }
            foreach ($dynamicUserIds as $dynamicUserId) {
                if ($dynamicUserId !== $currentUserId) {
                    return true;
                }
            }
        }
    }
    return false;
}

$companySql = <<<SQL
SELECT c.id,c.name,owner_role.id AS owner_role_id,
       (SELECT COUNT(*)
          FROM company_memberships om
          JOIN user_accounts oa ON oa.id=om.account_id AND oa.status='active'
          JOIN company_roles orole ON orole.id=om.role_id AND orole.company_id=om.company_id
         WHERE om.company_id=c.id AND om.status='active' AND orole.system_key='company_owner') AS owner_count,
       (SELECT COUNT(*)
          FROM company_memberships mm
          JOIN user_accounts aa ON aa.id=mm.account_id AND aa.status='active'
          JOIN company_roles rr ON rr.id=mm.role_id AND rr.company_id=mm.company_id
         WHERE mm.company_id=c.id AND mm.status='active' AND rr.is_system=0 AND rr.system_key IS NULL) AS ordinary_count
  FROM companies c
  JOIN company_access_readiness ar ON ar.company_id=c.id AND ar.state='ready'
       AND ar.verified_at IS NOT NULL AND ar.activated_at IS NOT NULL
  JOIN company_roles owner_role ON owner_role.company_id=c.id
       AND owner_role.system_key='company_owner' AND owner_role.is_system=1
 WHERE c.status='active'
 ORDER BY ordinary_count DESC, owner_count DESC, c.id
SQL;
$companies = $pdo->query($companySql)->fetchAll(PDO::FETCH_ASSOC);
$target = null;
foreach ($companies as $row) {
    if ((int)$row['owner_count'] <= 0 || (int)$row['ordinary_count'] <= 0) {
        continue;
    }
    $companyStmt = $pdo->prepare('SELECT * FROM companies WHERE id=? AND status=\'active\'');
    $companyStmt->execute([(int)$row['id']]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        continue;
    }
    try {
        $tenant = (new App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
        $recipients = App\Service\EntityResponsibilityService::eligibleRecipients($pdo, $tenant, (int)$row['id']);
    } catch (Throwable) {
        continue;
    }
    if (count($recipients) < 2) {
        continue;
    }
    $dynamic = $pdo->prepare(
        "SELECT m.id
           FROM company_memberships m
           JOIN user_accounts a ON a.id=m.account_id AND a.status='active'
           JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
          WHERE m.company_id=? AND m.status='active' AND r.is_system=0 AND r.system_key IS NULL"
    );
    $dynamic->execute([(int)$row['id']]);
    $dynamicMembershipIds = array_fill_keys(array_map('intval', $dynamic->fetchAll(PDO::FETCH_COLUMN)), true);
    $dynamicRecipients = array_values(array_filter(
        $recipients,
        static fn(array $recipient): bool => isset($dynamicMembershipIds[(int)$recipient['membership_id']])
    ));
    if ($dynamicRecipients === [] || !hasReversibleDynamicCandidate($tenant, $recipients, $dynamicRecipients)) {
        continue;
    }
    $target = $row;
    break;
}
if (!$target) { fwrite(STDERR, "no ready QA company with reversible dynamic responsibility fixture\n"); exit(1); }
$companyId = (int)$target['id'];
$ownerRoleId = (int)$target['owner_role_id'];
$foreign = $pdo->prepare(
    "SELECT m.id FROM company_memberships m
     JOIN user_accounts a ON a.id=m.account_id AND a.status='active'
     JOIN companies c ON c.id=m.company_id AND c.status='active'
     JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
     WHERE m.company_id<>? AND m.status='active' ORDER BY m.company_id,m.id LIMIT 1"
);
$foreign->execute([$companyId]);
$foreignMembershipId = (int)($foreign->fetchColumn() ?: 0);
if ($foreignMembershipId <= 0) { fwrite(STDERR, "no foreign active membership for isolation proof\n"); exit(1); }

$login = 'qa_func_' . $runId . '_' . bin2hex(random_bytes(5));
$password = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
$created = null;
try {
    $created = (new App\Service\CompanyMembershipService($pdo))->add(
        $companyId, $ownerRoleId, 'QA Functional ' . $runId, $login, $password, null, null, 'active'
    );
    if (!($created['account_created'] ?? false)) throw new RuntimeException('QA account unexpectedly reused existing identity');
    $state = [
        'account_id'=>(int)$created['account_id'], 'membership_id'=>(int)$created['membership_id'],
        'company_id'=>$companyId, 'normalized_login'=>App\Service\IdentityNormalizer::login($login),
        'foreign_membership_id'=>$foreignMembershipId,
    ];
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($stateFile, 0600);
    $payload = base64_encode(json_encode([[ 'role'=>'OWNER', 'username'=>$login, 'password'=>$password ]], JSON_THROW_ON_ERROR));
    echo 'QA_CREDENTIALS_B64=' . $payload . PHP_EOL;
    echo 'QA_FOREIGN_MEMBERSHIP_ID=' . $foreignMembershipId . PHP_EOL;
    echo 'QA_IDENTITY_CREATED=YES' . PHP_EOL;
} catch (Throwable $e) {
    if (is_array($created)) {
        try {
            $mid=(int)($created['membership_id']??0); $aid=(int)($created['account_id']??0);
            if ($mid>0) (new App\Service\CompanyMembershipService($pdo))->remove($companyId,$mid);
            if ($aid>0) { $d=$pdo->prepare('DELETE FROM user_accounts WHERE id=? AND NOT EXISTS (SELECT 1 FROM company_memberships WHERE account_id=user_accounts.id)'); $d->execute([$aid]); }
        } catch (Throwable) {}
    }
    @unlink($stateFile);
    fwrite(STDERR, "QA identity create failed: " . $e->getMessage() . "\n"); exit(1);
}
