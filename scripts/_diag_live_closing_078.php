<?php

declare(strict_types=1);

$root = '/home/s/spugovxsim/planexp/public_html/erpv2';
chdir($root);
$config = require 'bootstrap/app.php';
require 'app/Support/entrypoint_dependencies.php';
require 'app/Http/Controllers/Company/ClosingDocumentsController.php';

$_SESSION = ['user_id' => 1, 'role_code' => 'company_owner', 'company_id' => 25];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/erpv2/company/finance/closing-documents';
$db = new \App\Core\Database($config['database']);

ob_start();
(new \App\Http\Controllers\Company\ClosingDocumentsController($config, $db))->index();
$main = (string) ob_get_clean();
if (strpos($main, 'Закрывающие документы') === false || strpos($main, 'Исключения по документам') === false) {
    fwrite(STDERR, "main render markers missing\n");
    exit(7);
}

ob_start();
(new \App\Http\Controllers\Company\ClosingDocumentsController($config, $db))->exceptions();
$exceptions = (string) ob_get_clean();
if (strpos($exceptions, 'Исключения по документам') === false) {
    fwrite(STDERR, "exceptions render marker missing\n");
    exit(8);
}

$central = $db->connection();
$stmt = $central->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([25]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    fwrite(STDERR, "company missing\n");
    exit(9);
}
$tenant = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
$q = $tenant->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finance_closing_document_exceptions'");
if ((int) $q->fetchColumn() !== 1) {
    fwrite(STDERR, "078 table missing\n");
    exit(10);
}
$rows = \App\Service\ClosingDocumentService::reportRows($tenant, false);
$problems = \App\Service\ClosingDocumentService::problemCount($tenant);
printf("POSTDEPLOY_CLOSING_078_LIVE_RENDER=PASS rows=%d problems=%d\n", count($rows), $problems);
