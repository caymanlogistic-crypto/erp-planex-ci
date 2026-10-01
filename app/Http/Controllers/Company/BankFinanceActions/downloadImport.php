<?php

$companyId = (int)(getSessionCompanyId() ?? 0);
if ($companyId <= 0 || $bankImportId <= 0) {
    http_response_code(404);
    return;
}

try {
    $stmt = $db->connection()->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active'");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        http_response_code(404);
        return;
    }

    $localPdo = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    $import = \App\Service\BankFinanceService::getImportById($localPdo, $bankImportId);
    if (!$import) {
        http_response_code(404);
        return;
    }

    $store = new \App\Service\BankStatementFileStore(company_storage_path($company['storage_path'] ?? null, $companyId));
    $file = $store->verifiedPath((string)$import['file_hash']);
    if ($file === null) {
        http_response_code(404);
        return;
    }

    $name = \App\Service\DocumentService::safeDownloadName((string)($import['filename'] ?: 'bank-statement.xlsx'));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
        $name .= '.xlsx';
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="bank-statement.xlsx"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
} catch (\Throwable $e) {
    error_log('Bank statement download failed: company=' . $companyId . ' import=' . $bankImportId . ' error=' . $e->getMessage());
    http_response_code(500);
}
