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

function restoreQaResponsibilityFixture(PDO $pdo, array $config, ?array $state): void
{
    $fixture = is_array($state) ? ($state['responsibility_fixture'] ?? null) : null;
    if (!is_array($fixture)) {
        return;
    }
    $companyId = (int)($state['company_id'] ?? 0);
    $entityType = (string)($fixture['entity_type'] ?? '');
    $entityId = (int)($fixture['entity_id'] ?? 0);
    if ($companyId <= 0 || !App\Service\EntityResponsibilityService::isSupported($entityType) || $entityId <= 0) {
        throw new RuntimeException('invalid QA responsibility fixture cleanup state');
    }
    $companyStmt = $pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new RuntimeException('QA responsibility fixture company unavailable during cleanup');
    }
    $tenant = (new App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    $original = $fixture['original'] ?? null;
    $tenant->beginTransaction();
    try {
        $lock = $tenant->prepare(
            'SELECT membership_id,assigned_by_membership_id,created_at,updated_at '
            . 'FROM entity_responsibilities WHERE entity_type=? AND entity_id=? FOR UPDATE'
        );
        $lock->execute([$entityType, $entityId]);
        $lock->fetch(PDO::FETCH_ASSOC);
        if (is_array($original)) {
            $restore = $tenant->prepare(
                'INSERT INTO entity_responsibilities '
                . '(entity_type,entity_id,membership_id,assigned_by_membership_id,created_at,updated_at) '
                . 'VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '
                . 'membership_id=VALUES(membership_id),assigned_by_membership_id=VALUES(assigned_by_membership_id),'
                . 'created_at=VALUES(created_at),updated_at=VALUES(updated_at)'
            );
            $restore->execute([
                $entityType,
                $entityId,
                (int)($original['membership_id'] ?? 0),
                $original['assigned_by_membership_id'] !== null ? (int)$original['assigned_by_membership_id'] : null,
                (string)($original['created_at'] ?? ''),
                (string)($original['updated_at'] ?? ''),
            ]);
        } else {
            $delete = $tenant->prepare('DELETE FROM entity_responsibilities WHERE entity_type=? AND entity_id=?');
            $delete->execute([$entityType, $entityId]);
        }
        $tenant->commit();
    } catch (Throwable $e) {
        if ($tenant->inTransaction()) {
            $tenant->rollBack();
        }
        throw $e;
    }

    $verify = $tenant->prepare(
        'SELECT membership_id,assigned_by_membership_id,created_at,updated_at '
        . 'FROM entity_responsibilities WHERE entity_type=? AND entity_id=? LIMIT 1'
    );
    $verify->execute([$entityType, $entityId]);
    $after = $verify->fetch(PDO::FETCH_ASSOC);
    if (is_array($original)) {
        $expected = [
            'membership_id' => (int)($original['membership_id'] ?? 0),
            'assigned_by_membership_id' => $original['assigned_by_membership_id'] !== null ? (int)$original['assigned_by_membership_id'] : null,
            'created_at' => (string)($original['created_at'] ?? ''),
            'updated_at' => (string)($original['updated_at'] ?? ''),
        ];
        $actual = is_array($after) ? [
            'membership_id' => (int)($after['membership_id'] ?? 0),
            'assigned_by_membership_id' => $after['assigned_by_membership_id'] !== null ? (int)$after['assigned_by_membership_id'] : null,
            'created_at' => (string)($after['created_at'] ?? ''),
            'updated_at' => (string)($after['updated_at'] ?? ''),
        ] : null;
        if ($actual !== $expected) {
            throw new RuntimeException('QA responsibility fixture original row did not restore exactly');
        }
    } elseif ($after !== false) {
        throw new RuntimeException('QA responsibility fixture row residue detected');
    }
}

function cleanupQaContractorFixture(PDO $pdo, array $config, ?array $state): void
{
    $fixture = is_array($state) ? ($state['qa_contractor_fixture'] ?? null) : null;
    if (!is_array($fixture)) {
        return;
    }
    $companyId = (int)($state['company_id'] ?? 0);
    $contractorId = (int)($fixture['id'] ?? 0);
    $name = (string)($fixture['name'] ?? '');
    $inn = (string)($fixture['inn'] ?? '');
    $expectedPrefix = 'QA Functional Contractor ';
    if ($companyId <= 0 || $name === '' || !str_starts_with($name, $expectedPrefix)
        || !preg_match('/^99[0-9]{10}$/D', $inn)) {
        throw new RuntimeException('invalid QA contractor fixture cleanup state');
    }
    $companyStmt = $pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new RuntimeException('QA contractor fixture company unavailable during cleanup');
    }
    $tenant = (new App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    $lookupSql = $contractorId > 0
        ? 'SELECT id,name,inn FROM contractors WHERE id=? AND name=? AND inn=? LIMIT 2'
        : 'SELECT id,name,inn FROM contractors WHERE name=? AND inn=? ORDER BY id LIMIT 2';
    $lookup = $tenant->prepare($lookupSql);
    $lookup->execute($contractorId > 0 ? [$contractorId, $name, $inn] : [$name, $inn]);
    $rows = $lookup->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) {
        throw new RuntimeException('ambiguous QA contractor fixture namespace');
    }
    if ($rows !== []) {
        $actualId = (int)$rows[0]['id'];
        $responsibility = $tenant->prepare(
            "SELECT COUNT(*) FROM entity_responsibilities WHERE entity_type='contractor' AND entity_id=?"
        );
        $responsibility->execute([$actualId]);
        if ((int)$responsibility->fetchColumn() !== 0) {
            throw new RuntimeException('QA contractor fixture still has responsibility residue');
        }
        $delete = $tenant->prepare('DELETE FROM contractors WHERE id=? AND name=? AND inn=?');
        $delete->execute([$actualId, $name, $inn]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('QA contractor fixture cleanup did not delete exactly one row');
        }
    }
    $verify = $tenant->prepare('SELECT COUNT(*) FROM contractors WHERE name=? AND inn=?');
    $verify->execute([$name, $inn]);
    if ((int)$verify->fetchColumn() !== 0) {
        throw new RuntimeException('QA contractor fixture residue detected');
    }
}

function cleanupQaIdentity(PDO $pdo, array $config, string $stateFile, string $runId): void
{
    $prefix = qaIdentityPrefix($runId);
    $expectedAccountId = 0;
    $expectedMembershipId = 0;
    $state = null;
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

    // Responsibility fixture state is written before the tenant mutation. Restore
    // the exact pre-run row before removing the temporary owner membership.
    restoreQaResponsibilityFixture($pdo, $config, $state);
    cleanupQaContractorFixture($pdo, $config, $state);

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
    echo "QA_RESPONSIBILITY_FIXTURE_RESIDUE=0\n";
    echo "QA_CONTRACTOR_FIXTURE_RESIDUE=0\n";
    echo "QA_IDENTITY_RESIDUE=0\n";
}

if ($mode === 'cleanup') {
    cleanupQaIdentity($pdo, $config, $stateFile, $runId);
    exit(0);
}
if ($mode !== 'create') { fwrite(STDERR, "unknown QA identity mode\n"); exit(1); }
if (is_file($stateFile)) { fwrite(STDERR, "QA identity state already exists\n"); exit(1); }


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
    if ($dynamicRecipients === []) {
        continue;
    }
    $dynamicTarget = $dynamicRecipients[0];
    $baselineRecipient = null;
    foreach ($recipients as $recipient) {
        if ((int)$recipient['id'] !== (int)$dynamicTarget['id']) {
            $baselineRecipient = $recipient;
            break;
        }
    }
    if ($baselineRecipient === null) {
        continue;
    }
    $target = [
        'row' => $row,
        'company' => $company,
        'tenant' => $tenant,
        'baseline_recipient' => $baselineRecipient,
        'dynamic_recipient' => $dynamicTarget,
    ];
    break;
}
if (!$target) { fwrite(STDERR, "no ready QA company with two projected recipients including a dynamic role\n"); exit(1); }
$companyId = (int)$target['row']['id'];
$ownerRoleId = (int)$target['row']['owner_role_id'];
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
    $tenant = $target['tenant'];
    $contractorName = 'QA Functional Contractor ' . $runId;
    $contractorInn = '99' . str_pad((string)(((int)$runId) % 10000000000), 10, '0', STR_PAD_LEFT);
    $collision = $tenant->prepare('SELECT COUNT(*) FROM contractors WHERE name=? OR inn=?');
    $collision->execute([$contractorName, $contractorInn]);
    if ((int)$collision->fetchColumn() !== 0) {
        throw new RuntimeException('QA contractor fixture namespace collision');
    }
    $state = [
        'account_id'=>(int)$created['account_id'], 'membership_id'=>(int)$created['membership_id'],
        'company_id'=>$companyId, 'normalized_login'=>App\Service\IdentityNormalizer::login($login),
        'foreign_membership_id'=>$foreignMembershipId,
        'qa_contractor_fixture' => [
            'id' => 0,
            'name' => $contractorName,
            'inn' => $contractorInn,
        ],
    ];
    // Persist recovery identity and contractor namespace before creating any tenant fixture row.
    $stateJson = json_encode($state, JSON_THROW_ON_ERROR);
    if (file_put_contents($stateFile, $stateJson, LOCK_EX) === false || !chmod($stateFile, 0600)) {
        throw new RuntimeException('QA identity/fixture state persistence failed');
    }
    $insertContractor = $tenant->prepare(
        "INSERT INTO contractors (name,inn,status) VALUES (?,?,'active')"
    );
    $insertContractor->execute([$contractorName, $contractorInn]);
    $entityId = (int)$tenant->lastInsertId();
    if ($entityId <= 0) {
        throw new RuntimeException('QA contractor fixture insert did not return an id');
    }
    $state['qa_contractor_fixture']['id'] = $entityId;
    $state['responsibility_fixture'] = [
        'entity_type' => 'contractor',
        'entity_id' => $entityId,
        'baseline_membership_id' => (int)$target['baseline_recipient']['membership_id'],
        'dynamic_membership_id' => (int)$target['dynamic_recipient']['membership_id'],
        'original' => null,
    ];
    // Persist the concrete contractor id before creating the responsibility baseline.
    $stateJson = json_encode($state, JSON_THROW_ON_ERROR);
    if (file_put_contents($stateFile, $stateJson, LOCK_EX) === false || !chmod($stateFile, 0600)) {
        throw new RuntimeException('QA contractor fixture state update failed');
    }
    $fixture = $tenant->prepare(
        'INSERT INTO entity_responsibilities(entity_type,entity_id,membership_id,assigned_by_membership_id) '
        . 'VALUES(?,?,?,?)'
    );
    $fixture->execute([
        'contractor',
        $entityId,
        (int)$target['baseline_recipient']['membership_id'],
        (int)$created['membership_id'],
    ]);
    if (App\Service\EntityResponsibilityService::effectiveBaseMembershipId($tenant, 'contractor', $entityId)
        !== (int)$target['baseline_recipient']['membership_id']) {
        throw new RuntimeException('QA responsibility fixture baseline readback failed');
    }
    $payload = base64_encode(json_encode([[ 'role'=>'OWNER', 'username'=>$login, 'password'=>$password ]], JSON_THROW_ON_ERROR));
    echo 'QA_CREDENTIALS_B64=' . $payload . PHP_EOL;
    echo 'QA_FOREIGN_MEMBERSHIP_ID=' . $foreignMembershipId . PHP_EOL;
    echo 'QA_CONTRACTOR_FIXTURE_READY=YES' . PHP_EOL;
    echo 'QA_RESPONSIBILITY_FIXTURE_READY=YES' . PHP_EOL;
    echo 'QA_IDENTITY_CREATED=YES' . PHP_EOL;
} catch (Throwable $e) {
    try {
        cleanupQaIdentity($pdo, $config, $stateFile, $runId);
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "QA create cleanup failed: " . $cleanupError->getMessage() . "\n");
    }
    fwrite(STDERR, "QA identity/fixture create failed: " . $e->getMessage() . "\n"); exit(1);
}
