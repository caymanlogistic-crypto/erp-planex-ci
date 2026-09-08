<?php

declare(strict_types=1);

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ''), '/');
$runId = trim((string) (getenv('QA_RUN_ID') ?: ''));
if ($root === '' || !preg_match('/^[0-9]{1,20}$/D', $runId) || !is_file($root . '/.env')) {
    fwrite(STDERR, "invalid QA environment\n");
    exit(1);
}

$stateFile = '/tmp/docgen_manager_access_' . $runId . '.json';
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/IdentityNormalizer.php';
require_once $root . '/app/Service/CompanyRoleService.php';
require_once $root . '/app/Service/CompanyMembershipService.php';
require_once $root . '/app/Security/PageRegistry.php';
$config = require $root . '/bootstrap/app.php';
$central = (new App\Core\Database($config['database']))->connection();
$mode = (string) ($argv[1] ?? '');
$companyId = 25;
$login = 'qa_docgen_' . $runId;
$roleName = 'QA DocGen Manager ' . $runId;
$documentNumber = 'QA-ACCESS-' . $runId . '-OWN';

function qaDocgenStateWrite(string $path, array $state): void
{
    if (file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('QA state write failed');
    }
}

function qaDocgenTenant(PDO $central, array $config, int $companyId): PDO
{
    $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) {
        throw new RuntimeException('QA company unavailable');
    }
    return (new App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
}

function qaDocgenFingerprint(?array $row): string
{
    return hash('sha256', json_encode($row ?: [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function qaDocgenCreateDocx(string $path, string $label): string
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('QA DOCX open failed');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $sha = hash_file('sha256', $path) ?: '';
    if ($sha === '') {
        throw new RuntimeException('QA DOCX SHA failed');
    }
    return $sha;
}

/** @return array<string,int> */
function qaDocgenResidue(PDO $central, array $config, int $companyId, string $login, string $roleName, string $documentNumber, string $runId): array
{
    $tenant = qaDocgenTenant($central, $config, $companyId);
    $normalizedLogin = App\Service\IdentityNormalizer::login($login);
    $normalizedRole = App\Service\IdentityNormalizer::roleName($roleName);

    $stmt = $central->prepare('SELECT COUNT(*) FROM user_accounts WHERE normalized_login=?');
    $stmt->execute([$normalizedLogin]);
    $accounts = (int) $stmt->fetchColumn();
    $stmt = $central->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id=? AND normalized_name=?');
    $stmt->execute([$companyId, $normalizedRole]);
    $roles = (int) $stmt->fetchColumn();
    $stmt = $central->prepare('SELECT COUNT(*) FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.company_id=? AND a.normalized_login=?');
    $stmt->execute([$companyId, $normalizedLogin]);
    $memberships = (int) $stmt->fetchColumn();
    $stmt = $tenant->prepare('SELECT COUNT(*) FROM users WHERE login=?');
    $stmt->execute([$login]);
    $tenantUsers = (int) $stmt->fetchColumn();
    $stmt = $tenant->prepare('SELECT COUNT(*) FROM generated_documents WHERE document_number=?');
    $stmt->execute([$documentNumber]);
    $documents = (int) $stmt->fetchColumn();
    $expectedDir = storage_path("companies/{$companyId}/document-generator/qa-access-{$runId}");
    $storage = is_dir($expectedDir) ? 1 : 0;

    $cross = 0;
    $foreignCompany = $central->query("SELECT * FROM companies WHERE id=27 AND status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($foreignCompany) {
        $foreignTenant = (new App\Core\Database(companyDatabaseConfig($config, $foreignCompany)))->connection();
        $crossStmt = $foreignTenant->prepare('SELECT COUNT(*) FROM users WHERE login=?');
        $crossStmt->execute([$login]);
        $cross = (int) $crossStmt->fetchColumn();
    }

    return [
        'accounts' => $accounts,
        'roles' => $roles,
        'memberships' => $memberships,
        'tenant_users' => $tenantUsers,
        'documents' => $documents,
        'storage' => $storage,
        'cross_company_users' => $cross,
    ];
}

function qaDocgenAssertEmptyNamespace(PDO $central, array $config, int $companyId, string $login, string $roleName, string $documentNumber, string $runId): void
{
    $residue = qaDocgenResidue($central, $config, $companyId, $login, $roleName, $documentNumber, $runId);
    if (array_sum($residue) !== 0) {
        throw new RuntimeException('QA namespace not empty: ' . json_encode($residue, JSON_THROW_ON_ERROR));
    }
}

if ($mode === 'audit-empty') {
    qaDocgenAssertEmptyNamespace($central, $config, $companyId, $login, $roleName, $documentNumber, $runId);
    echo "QA_EMPTY_NAMESPACE=PASS\nQA_RESIDUE=0\n";
    exit(0);
}

if ($mode === 'create') {
    if (is_file($stateFile)) {
        fwrite(STDERR, "QA state already exists\n");
        exit(1);
    }
    $tenant = qaDocgenTenant($central, $config, $companyId);
    foreach (['users','crews','contractors','document_templates','document_template_versions','generated_documents'] as $table) {
        $tenant->query("SELECT 1 FROM `{$table}` LIMIT 1");
    }

    $templates = $tenant->query("SELECT t.id,t.name,t.document_type,t.active_version_id,v.version_no FROM document_templates t JOIN document_template_versions v ON v.id=t.active_version_id AND v.template_id=t.id WHERE t.status='active' ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($templates) < 2) {
        throw new RuntimeException('expected at least two active templates');
    }
    $crew = $tenant->query("SELECT c.id FROM crews c WHERE c.status='active' AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$crew) {
        throw new RuntimeException('active route executor missing');
    }
    $foreign = $tenant->query("SELECT id,document_number,sha256,stored_path,created_by_user_id,template_id,route_executor_id FROM generated_documents ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$foreign) {
        throw new RuntimeException('foreign history fixture missing');
    }
    $baselineCount = (int) $tenant->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn();
    $templateSnapshot = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'type' => (string) $row['document_type'],
        'version_id' => (int) $row['active_version_id'],
    ], $templates);

    $state = [
        'company_id' => $companyId,
        'run_id' => $runId,
        'login' => $login,
        'password' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
        'role_name' => $roleName,
        'role_id' => 0,
        'membership_id' => 0,
        'account_id' => 0,
        'tenant_user_id' => 0,
        'crew_id' => (int) $crew['id'],
        'templates' => $templateSnapshot,
        'templates_fingerprint' => qaDocgenFingerprint($templateSnapshot),
        'baseline_document_count' => $baselineCount,
        'foreign_document_id' => (int) $foreign['id'],
        'foreign_document_fingerprint' => qaDocgenFingerprint($foreign),
        'own_document_id' => 0,
        'own_document_number' => $documentNumber,
        'own_document_sha256' => '',
        'own_file' => '',
    ];
    qaDocgenStateWrite($stateFile, $state);

    $collision = $central->prepare('SELECT COUNT(*) FROM user_accounts WHERE normalized_login=?');
    $collision->execute([App\Service\IdentityNormalizer::login($login)]);
    if ((int) $collision->fetchColumn() !== 0) {
        throw new RuntimeException('QA account namespace collision');
    }
    $roleCollision = $central->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id=? AND normalized_name=?');
    $roleCollision->execute([$companyId, App\Service\IdentityNormalizer::roleName($roleName)]);
    if ((int) $roleCollision->fetchColumn() !== 0) {
        throw new RuntimeException('QA role namespace collision');
    }
    $docCollision = $tenant->prepare('SELECT COUNT(*) FROM generated_documents WHERE document_number=?');
    $docCollision->execute([$documentNumber]);
    if ((int) $docCollision->fetchColumn() !== 0) {
        throw new RuntimeException('QA document namespace collision');
    }

    $roleService = new App\Service\CompanyRoleService($central);
    $membershipService = new App\Service\CompanyMembershipService($central);
    $registry = new App\Security\PageRegistry();
    $role = $roleService->create($companyId, $roleName);
    $state['role_id'] = (int) $role['id'];
    qaDocgenStateWrite($stateFile, $state);

    $grantable = $registry->grantablePageKeys();
    $pages = array_fill_keys($grantable, false);
    foreach (['company.dashboard','company.route_executors','company.documents.create','company.documents.history'] as $key) {
        if (!array_key_exists($key, $pages)) {
            throw new RuntimeException('required page not grantable: ' . $key);
        }
        $pages[$key] = true;
    }
    $scopes = array_fill_keys(App\Service\CompanyRoleService::regulatedEntities(), 'all');
    $landing = array_values(array_map(
        static fn(array $page): string => (string) $page['key'],
        array_filter($registry->landingPages(), static fn(array $page): bool => $registry->isGrantable((string) $page['key']))
    ));
    $roleService->save($companyId, $state['role_id'], $roleName, $pages, $scopes, 'company.dashboard', $grantable, $landing);

    $added = $membershipService->add($companyId, $state['role_id'], 'QA DocGen Manager ' . $runId, $login, $state['password'], null, null, 'active');
    if (!($added['account_created'] ?? false)) {
        throw new RuntimeException('QA account was not new');
    }
    $state['membership_id'] = (int) $added['membership_id'];
    $state['account_id'] = (int) $added['account_id'];
    qaDocgenStateWrite($stateFile, $state);

    $insertUser = $tenant->prepare("INSERT INTO users(membership_id,full_name,login,password_hash,role_code,status) VALUES(?,?,?,?,?,'active')");
    $insertUser->execute([$state['membership_id'], 'QA DocGen Manager ' . $runId, $login, password_hash($state['password'], PASSWORD_DEFAULT), 'membership_role']);
    $state['tenant_user_id'] = (int) $tenant->lastInsertId();
    if ($state['tenant_user_id'] <= 0) {
        throw new RuntimeException('QA tenant user projection failed');
    }
    qaDocgenStateWrite($stateFile, $state);

    $template = $templates[0];
    $dir = storage_path("companies/{$companyId}/document-generator/qa-access-{$runId}");
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException('QA document directory create failed');
    }
    $file = $dir . '/own.docx';
    $state['own_file'] = $file;
    qaDocgenStateWrite($stateFile, $state);
    $sha = qaDocgenCreateDocx($file, $documentNumber);
    $relative = "companies/{$companyId}/document-generator/qa-access-{$runId}/own.docx";
    $snapshot = json_encode([
        'document' => ['number' => $documentNumber],
        'template' => ['name' => (string) $template['name'], 'version_no' => (int) $template['version_no']],
        'executor' => ['name' => 'QA access acceptance'],
        'driver' => ['full_name' => 'QA'],
        'vehicle' => ['model' => 'QA', 'plate' => 'QA'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insertDoc = $tenant->prepare("INSERT INTO generated_documents(document_type,document_number,document_date,template_id,template_version_id,route_executor_id,request_ids_json,total_mass_kg,unload_address,parameters_json,snapshot_json,stored_path,original_name,sha256,created_by_user_id,created_by_role) VALUES(?,?,CURDATE(),?,?,?,'[]',0,'QA acceptance','{}',?,?,?,?,?,?)");
    $insertDoc->execute([(string) $template['document_type'], $documentNumber, (int) $template['id'], (int) $template['active_version_id'], (int) $state['crew_id'], $snapshot, $relative, 'qa-access-own.docx', $sha, (int) $state['tenant_user_id'], 'membership_role']);
    $state['own_document_id'] = (int) $tenant->lastInsertId();
    $state['own_document_sha256'] = $sha;
    $state['own_file'] = $file;
    qaDocgenStateWrite($stateFile, $state);

    $credentials = base64_encode(json_encode(['login' => $login, 'password' => $state['password']], JSON_THROW_ON_ERROR));
    echo 'QA_CREDENTIALS_B64=' . $credentials . PHP_EOL;
    echo 'QA_OWN_DOCUMENT_ID=' . $state['own_document_id'] . PHP_EOL;
    echo 'QA_FOREIGN_DOCUMENT_ID=' . $state['foreign_document_id'] . PHP_EOL;
    echo 'QA_OWN_DOCUMENT_SHA256=' . $state['own_document_sha256'] . PHP_EOL;
    echo 'QA_CREW_ID=' . $state['crew_id'] . PHP_EOL;
    echo 'QA_TEMPLATES_B64=' . base64_encode(json_encode($state['templates'], JSON_THROW_ON_ERROR)) . PHP_EOL;
    echo 'QA_BASELINE_DOCUMENT_COUNT=' . $baselineCount . PHP_EOL;
    echo "QA_MANAGER_FIXTURE_READY=YES\n";
    exit(0);
}

if ($mode === 'cleanup') {
    if (!is_file($stateFile)) {
        qaDocgenAssertEmptyNamespace($central, $config, $companyId, $login, $roleName, $documentNumber, $runId);
        echo "QA_EARLY_SETUP_STATE_ABSENT=PASS\nQA_RESIDUE=0\n";
        exit(0);
    }
    $state = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    $tenant = qaDocgenTenant($central, $config, $companyId);

    $deleteDoc = $tenant->prepare('DELETE FROM generated_documents WHERE document_number=?');
    $deleteDoc->execute([$documentNumber]);
    $file = (string) ($state['own_file'] ?? '');
    $expectedDir = storage_path("companies/{$companyId}/document-generator/qa-access-{$runId}");
    if ($file !== '' && dirname($file) === $expectedDir) {
        @unlink($file);
    }
    if (is_dir($expectedDir)) {
        @rmdir($expectedDir);
    }

    $membershipId = (int) ($state['membership_id'] ?? 0);
    $tenantUserId = (int) ($state['tenant_user_id'] ?? 0);
    if ($tenantUserId > 0) {
        $deleteUser = $tenant->prepare('DELETE FROM users WHERE id=? AND login=?');
        $deleteUser->execute([$tenantUserId, $login]);
    } else {
        $deleteUser = $tenant->prepare('DELETE FROM users WHERE login=?');
        $deleteUser->execute([$login]);
    }

    $accountStmt = $central->prepare('SELECT id FROM user_accounts WHERE normalized_login=? LIMIT 2');
    $accountStmt->execute([App\Service\IdentityNormalizer::login($login)]);
    $accountRows = $accountStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($accountRows) > 1) {
        throw new RuntimeException('ambiguous QA account namespace during cleanup');
    }
    $accountId = $accountRows === [] ? 0 : (int) $accountRows[0];
    if ($accountId > 0) {
        $membershipStmt = $central->prepare('SELECT id FROM company_memberships WHERE account_id=? AND company_id=? LIMIT 2');
        $membershipStmt->execute([$accountId, $companyId]);
        $membershipRows = $membershipStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($membershipRows) > 1) {
            throw new RuntimeException('ambiguous QA membership namespace during cleanup');
        }
        if ($membershipRows !== []) {
            (new App\Service\CompanyMembershipService($central))->remove($companyId, (int) $membershipRows[0]);
        }
        $deleteAccount = $central->prepare('DELETE FROM user_accounts WHERE id=? AND normalized_login=? AND NOT EXISTS (SELECT 1 FROM company_memberships WHERE account_id=user_accounts.id)');
        $deleteAccount->execute([$accountId, App\Service\IdentityNormalizer::login($login)]);
    }

    $roleStmt = $central->prepare('SELECT id,is_system,system_key FROM company_roles WHERE company_id=? AND normalized_name=? LIMIT 2');
    $roleStmt->execute([$companyId, App\Service\IdentityNormalizer::roleName($roleName)]);
    $roleRows = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($roleRows) > 1) {
        throw new RuntimeException('ambiguous QA role namespace during cleanup');
    }
    if ($roleRows !== []) {
        $role = $roleRows[0];
        if ((int) $role['is_system'] !== 0 || $role['system_key'] !== null) {
            throw new RuntimeException('unexpected QA role shape during cleanup');
        }
        (new App\Service\CompanyRoleService($central))->delete($companyId, (int) $role['id']);
    }

    $foreignStmt = $tenant->prepare('SELECT id,document_number,sha256,stored_path,created_by_user_id,template_id,route_executor_id FROM generated_documents WHERE id=? LIMIT 1');
    $foreignStmt->execute([(int) $state['foreign_document_id']]);
    $foreign = $foreignStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (qaDocgenFingerprint($foreign) !== (string) $state['foreign_document_fingerprint']) {
        throw new RuntimeException('foreign generated document changed during QA');
    }
    $templates = $tenant->query("SELECT t.id,t.document_type,t.active_version_id FROM document_templates t JOIN document_template_versions v ON v.id=t.active_version_id AND v.template_id=t.id WHERE t.status='active' ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
    $templateSnapshot = array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'type'=>(string)$row['document_type'],'version_id'=>(int)$row['active_version_id']], $templates);
    if (qaDocgenFingerprint($templateSnapshot) !== (string) $state['templates_fingerprint']) {
        throw new RuntimeException('active templates changed during QA');
    }
    if ((int) $tenant->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn() !== (int) $state['baseline_document_count']) {
        throw new RuntimeException('generated document count not restored');
    }
    $residue = 0;
    $check = $central->prepare('SELECT COUNT(*) FROM user_accounts WHERE normalized_login=?');
    $check->execute([App\Service\IdentityNormalizer::login($login)]); $residue += (int) $check->fetchColumn();
    $check = $central->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id=? AND normalized_name=?');
    $check->execute([$companyId, App\Service\IdentityNormalizer::roleName($roleName)]); $residue += (int) $check->fetchColumn();
    $check = $tenant->prepare('SELECT COUNT(*) FROM users WHERE login=?');
    $check->execute([$login]); $residue += (int) $check->fetchColumn();
    $check = $tenant->prepare('SELECT COUNT(*) FROM generated_documents WHERE document_number=?');
    $check->execute([$documentNumber]); $residue += (int) $check->fetchColumn();
    if (is_file($file) || is_dir($expectedDir)) { $residue++; }

    $foreignCompany = $central->query("SELECT * FROM companies WHERE id=27 AND status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    $cross = 0;
    if ($foreignCompany) {
        $foreignTenant = (new App\Core\Database(companyDatabaseConfig($config, $foreignCompany)))->connection();
        $crossStmt = $foreignTenant->prepare('SELECT COUNT(*) FROM users WHERE login=?');
        $crossStmt->execute([$login]);
        $cross = (int) $crossStmt->fetchColumn();
    }
    if ($residue !== 0 || $cross !== 0) {
        throw new RuntimeException("QA residue={$residue} cross={$cross}");
    }
    if (!unlink($stateFile)) {
        throw new RuntimeException('QA state cleanup failed');
    }
    echo "FOREIGN_DOCUMENT_PRESERVED=PASS\n";
    echo "ACTIVE_TEMPLATES_PRESERVED=PASS\n";
    echo "BASELINE_DOCUMENT_COUNT_RESTORED=PASS\n";
    echo "CROSS_COMPANY_LEAKAGE=NO\n";
    echo "QA_RESIDUE=0\n";
    exit(0);
}

fwrite(STDERR, "unknown mode\n");
exit(1);
