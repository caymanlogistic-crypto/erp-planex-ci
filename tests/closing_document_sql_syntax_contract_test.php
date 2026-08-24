<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../app/Service/ClosingDocumentComplianceService.php');

if (str_contains($source, "\\n'")) {
    throw new RuntimeException('ClosingDocumentComplianceService contains a literal \\n escape inside SQL concatenation');
}

foreach ([
    'finance_closing_document_manual_statuses (bank_transaction_id, requirement_key, updated_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE',
    'finance_closing_document_requirement_exceptions (inn, requirement_key, created_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE',
    'INSERT INTO documents (entity_type, entity_id, document_type, original_name, stored_name, relative_path, mime_type, file_size, status, uploaded_by_user_id, uploaded_by_role, created_by_user_id, created_by_role) ',
    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
] as $token) {
    if (!str_contains($source, $token)) {
        throw new RuntimeException('Closing document SQL template missing: ' . $token);
    }
}

echo "closing_document_sql_syntax_contract_test: PASS\n";
