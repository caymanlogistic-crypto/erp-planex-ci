<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$tableExists = static function(PDO $pdo, string $table): int {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn();
};
$columnExists = static function(PDO $pdo, string $table, string $column): int {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]);
    return (int)$q->fetchColumn();
};
$columnType = static function(PDO $pdo, string $table, string $column): string {
    $q=$pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]);
    return (string)($q->fetchColumn() ?: '');
};
$columnDefault = static function(PDO $pdo, string $table, string $column): ?string {
    $q=$pdo->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]);
    $v=$q->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
};
$indexExists = static function(PDO $pdo, string $table, string $index): int {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $q->execute([$table,$index]);
    return (int)$q->fetchColumn() > 0 ? 1 : 0;
};
$migrationExists = static function(PDO $pdo, string $name) use ($tableExists): int {
    if ($tableExists($pdo,'schema_migrations') !== 1) return 0;
    $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
    $q->execute([$name]);
    return (int)$q->fetchColumn();
};
$hashRows = static function(PDO $pdo, string $sql): string {
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
};

$defaultCol=$columnExists($central,'company_roles','default_page_key');
$centralSnapshot=[
    'db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),
    'active_companies'=>(int)$central->query("SELECT COUNT(*) FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>''")->fetchColumn(),
    'company_roles'=>(int)$central->query('SELECT COUNT(*) FROM company_roles')->fetchColumn(),
    'company_memberships'=>(int)$central->query('SELECT COUNT(*) FROM company_memberships')->fetchColumn(),
    'role_core_hash'=>$hashRows($central,"SELECT id,company_id,name,normalized_name,system_key,is_system FROM company_roles ORDER BY id"),
    'migration_013'=>$migrationExists($central,'013_create_roles_access.sql'),
    'migration_014'=>$migrationExists($central,'014_add_role_default_page.sql'),
    'default_page_column'=>$defaultCol,
    'default_page_index'=>$indexExists($central,'company_roles','idx_company_roles_default_page'),
    'owner_roles'=>(int)$central->query("SELECT COUNT(*) FROM company_roles WHERE system_key='company_owner' AND is_system=1")->fetchColumn(),
    'owner_dashboard_defaults'=>$defaultCol ? (int)$central->query("SELECT COUNT(*) FROM company_roles WHERE system_key='company_owner' AND is_system=1 AND default_page_key='company.dashboard'")->fetchColumn() : 0,
    'custom_defaults'=>$defaultCol ? (int)$central->query("SELECT COUNT(*) FROM company_roles WHERE is_system=0 AND default_page_key IS NOT NULL")->fetchColumn() : 0,
];

$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');
$tenants=[];
foreach($companies as $company){
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    $kindCol=$columnExists($pdo,'entity_assignments','assignment_kind');
    $respTable=$tableExists($pdo,'entity_responsibilities');
    $assignmentRows=$tableExists($pdo,'entity_assignments') ? (int)$pdo->query('SELECT COUNT(*) FROM entity_assignments')->fetchColumn() : -1;
    $kinds=['legacy_unclassified'=>0,'creator_auto'=>0,'manual'=>0];
    if($kindCol){
        foreach($pdo->query('SELECT assignment_kind,COUNT(*) c FROM entity_assignments GROUP BY assignment_kind')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $kinds[(string)$row['assignment_kind']]=(int)$row['c'];
        }
    }
    $tenants[]=[
        'company_id'=>(int)$company['id'],
        'db'=>(string)$company['db_identifier'],
        'migration_131'=>$migrationExists($pdo,'131_create_roles_access_projection.sql'),
        'migration_132'=>$migrationExists($pdo,'132_split_assignment_and_responsibility.sql'),
        'assignment_rows'=>$assignmentRows,
        'assignment_core_hash'=>$tableExists($pdo,'entity_assignments') ? $hashRows($pdo,"SELECT id,entity_type,entity_id,membership_id,assigned_by_membership_id,created_at,updated_at FROM entity_assignments ORDER BY id") : '',
        'assignment_kind_column'=>$kindCol,
        'assignment_kind_type'=>$kindCol ? $columnType($pdo,'entity_assignments','assignment_kind') : '',
        'assignment_kind_default'=>$kindCol ? $columnDefault($pdo,'entity_assignments','assignment_kind') : null,
        'old_unique'=>$indexExists($pdo,'entity_assignments','uk_entity_assignment'),
        'kind_unique'=>$indexExists($pdo,'entity_assignments','uk_entity_assignment_kind'),
        'kind_index'=>$indexExists($pdo,'entity_assignments','idx_assignment_kind'),
        'legacy_kind_rows'=>$kinds['legacy_unclassified']??0,
        'creator_kind_rows'=>$kinds['creator_auto']??0,
        'manual_kind_rows'=>$kinds['manual']??0,
        'responsibilities_table'=>$respTable,
        'responsibilities_rows'=>$respTable ? (int)$pdo->query('SELECT COUNT(*) FROM entity_responsibilities')->fetchColumn() : 0,
    ];
}

echo 'ROLES_ACCESS_132_SNAPSHOT='.json_encode(['central'=>$centralSnapshot,'tenants'=>$tenants],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo "ROLES_ACCESS_132_READ_ONLY_OK\n";
