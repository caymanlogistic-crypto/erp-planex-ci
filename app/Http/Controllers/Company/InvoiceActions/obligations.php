<?php

use App\Core\Database;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;

header('Content-Type: application/json; charset=utf-8');

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        echo json_encode([]);
        exit;
    }
    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($localPdo);

    $direction = strtoupper(trim((string)($_GET['direction'] ?? '')));
    if (!in_array($direction, [FinanceInvoiceService::DIRECTION_OUTGOING, FinanceInvoiceService::DIRECTION_INCOMING], true)) {
        echo json_encode([]);
        exit;
    }
    $counterparty = trim((string)($_GET['counterparty'] ?? ''));
    if ($counterparty === '') {
        echo json_encode([]);
        exit;
    }
    [$type, $rawId] = array_pad(explode(':', $counterparty, 2), 2, null);
    $id = (int)$rawId;
    $expectedType = $direction === FinanceInvoiceService::DIRECTION_OUTGOING ? 'client' : 'contractor';
    if ($type !== $expectedType || $id <= 0) {
        echo json_encode([]);
        exit;
    }

    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    $rows = FinanceObligationService::fetchCompatibleForInvoice(
        $localPdo,
        $direction,
        $type,
        $id,
        $invoiceId > 0 ? $invoiceId : null
    );
    $result = array_map(static function (array $row): array {
        $due = $row['due_date'] ?? $row['forecast_due_date'] ?? null;
        return [
            'id' => (int)$row['id'],
            'route_id' => (int)$row['source_parent_id'],
            'trip_business_id' => isset($row['trip_business_id']) ? (int)$row['trip_business_id'] : null,
            'route_label' => (string)($row['parent_label'] ?? ('Рейс #' . (int)$row['source_parent_id'])),
            'counterparty' => (string)$row['counterparty_name'],
            'condition' => (string)$row['condition_label'],
            'due_date' => $due,
            'amount' => (string)$row['amount'],
            'available' => (string)$row['available_to_invoice'],
            'current_amount' => (string)$row['current_invoice_link_amount'],
            'status' => (string)$row['status'],
        ];
    }, $rows);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;
