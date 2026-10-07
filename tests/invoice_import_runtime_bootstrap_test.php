<?php
declare(strict_types=1);

// Run in a fresh process so an explicit parser require in another test cannot hide a missing route dependency.
$config = require dirname(__DIR__) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
$db = new class([]) extends App\Core\Database {
    public function connection(): PDO { throw new RuntimeException('Unexpected DB access during route bootstrap.'); }
};
$router = new App\Http\Router();
require BASE_PATH . '/app/Http/Routes/company_finance_invoices.php';
if (!class_exists(App\Service\GeneratedClientInvoiceDocxParser::class, false)) {
    throw new RuntimeException('Invoice import parser is missing from route bootstrap.');
}
echo "INVOICE_IMPORT_RUNTIME_BOOTSTRAP=PASS\n";

