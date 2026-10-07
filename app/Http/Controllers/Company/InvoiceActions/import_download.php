<?php

use App\Core\Database;
use App\Service\DocumentService;

$companyId = (int)(getSessionCompanyId() ?? 0);
$invoiceId = (int)$id;
try {
    $company = $db->fetch('SELECT * FROM companies WHERE id=?', [$companyId]);
    if (!$company || ($company['status'] ?? '') !== 'active' || $invoiceId <= 0) {
        http_response_code(404); exit;
    }
    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($localPdo);
    $stmt = $localPdo->prepare("SELECT d.* FROM documents d JOIN finance_invoices i ON i.id=d.entity_id WHERE d.entity_type='finance_invoice' AND d.entity_id=? AND d.deleted_at IS NULL ORDER BY d.id DESC LIMIT 1");
    $stmt->execute([$invoiceId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    $path = $document ? DocumentService::resolveStoredPath($companyId, (string)$document['relative_path']) : null;
    if ($path === null) { http_response_code(404); exit; }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(DocumentService::safeDownloadName((string)$document['original_name'])));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $e) {
    error_log('Invoice DOCX download failed: ' . $e->getMessage());
    http_response_code(500);
}
exit;
