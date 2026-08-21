<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class ClosingDocumentService
{
    private const PROFILE_LABELS = [
        'legal_entity' => 'Юридическое лицо',
        'individual' => 'ИП',
        'self_employed' => 'Самозанятый / НПД',
        'private_person' => 'Физическое лицо',
    ];

    /**
     * Requirement groups are the V1 business contract. A group is satisfied
     * when at least one linked document has one of its accepted codes.
     */
    private const PROFILES = [
        'legal_entity' => [
            ['key' => 'agreement', 'label' => 'Договор', 'accepted' => ['agreement']],
            ['key' => 'act_or_upd', 'label' => 'Акт / УПД', 'accepted' => ['act', 'upd']],
        ],
        'individual' => [
            ['key' => 'agreement', 'label' => 'Договор', 'accepted' => ['agreement']],
            ['key' => 'act_or_upd', 'label' => 'Акт / УПД', 'accepted' => ['act', 'upd']],
        ],
        'self_employed' => [
            ['key' => 'agreement', 'label' => 'Договор', 'accepted' => ['agreement']],
            ['key' => 'act', 'label' => 'Акт', 'accepted' => ['act']],
            ['key' => 'npd_receipt', 'label' => 'Чек НПД', 'accepted' => ['npd_receipt']],
        ],
        'private_person' => [
            ['key' => 'gph_agreement', 'label' => 'Договор ГПХ', 'accepted' => ['gph_agreement']],
            ['key' => 'act', 'label' => 'Акт', 'accepted' => ['act']],
        ],
    ];

    private const REQUIREMENT_LABELS = [
        'agreement' => 'Договор',
        'act' => 'Акт',
        'upd' => 'УПД',
        'npd_receipt' => 'Чек НПД',
        'gph_agreement' => 'Договор ГПХ',
    ];

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM finance_closing_counterparties LIMIT 1')->fetchColumn();
            $pdo->query('SELECT 1 FROM finance_closing_document_links LIMIT 1')->fetchColumn();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function profiles(): array
    {
        return self::PROFILES;
    }

    public static function profileFor(?string $legalType): ?array
    {
        $legalType = trim((string) $legalType);
        if ($legalType === '' || !isset(self::PROFILES[$legalType])) {
            return null;
        }

        return [
            'code' => $legalType,
            'label' => self::PROFILE_LABELS[$legalType],
            'requirements' => self::PROFILES[$legalType],
        ];
    }

    public static function requirementLabels(): array
    {
        return self::REQUIREMENT_LABELS;
    }

    /**
     * SINGLE SOURCE OF TRUTH for both the report and menu badge.
     * Filtering is deliberately performed only after enrich/evaluateProblem,
     * so no second SQL predicate can drift from the report behavior.
     */
    public static function reportRows(PDO $pdo, bool $problemOnly = false): array
    {
        if (!self::schemaReady($pdo)) {
            return [];
        }

        $rows = $pdo->query(
            "SELECT bt.id, bt.account_id, bt.operation_date, bt.document_number,
                    bt.operation_type, bt.counterparty_name, bt.counterparty_inn,
                    bt.debit_amount, bt.credit_amount, bt.purpose,
                    bt.classification_status, COALESCE(bt.is_internal_transfer, 0) AS is_internal_transfer,
                    fcc.entity_type AS assigned_entity_type, fcc.entity_id AS assigned_entity_id
             FROM bank_transactions bt
             LEFT JOIN finance_closing_counterparties fcc ON fcc.bank_transaction_id = bt.id
             WHERE COALESCE(bt.is_internal_transfer, 0) = 0
               AND NOT EXISTS (
                    SELECT 1 FROM finance_employee_movements fem
                    WHERE fem.bank_transaction_id = bt.id
               )
             ORDER BY bt.operation_date DESC, bt.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $linksByTransaction = self::loadLinksByTransaction($pdo);
        $entityCache = [];
        $result = [];

        foreach ($rows as $row) {
            $counterparty = self::resolveCounterparty($pdo, $row, $entityCache);
            $profile = self::profileFor($counterparty['legal_type'] ?? null);
            $links = $linksByTransaction[(int) $row['id']] ?? [];
            $evaluation = self::evaluateProblem($counterparty, $profile, $links);

            $row['counterparty'] = $counterparty;
            $row['profile'] = $profile;
            $row['links'] = $links;
            $row['missing_requirements'] = $evaluation['missing_requirements'];
            $row['is_problem'] = $evaluation['is_problem'];
            $row['problem_reason'] = $evaluation['problem_reason'];

            if (!$problemOnly || $row['is_problem']) {
                $result[] = $row;
            }
        }

        return $result;
    }

    public static function problemCount(PDO $pdo): int
    {
        return count(self::reportRows($pdo, true));
    }

    public static function evaluateProblem(?array $counterparty, ?array $profile, array $links): array
    {
        if ($counterparty === null) {
            return [
                'is_problem' => true,
                'problem_reason' => 'Контрагент не определён',
                'missing_requirements' => [],
            ];
        }

        if ($profile === null) {
            return [
                'is_problem' => true,
                'problem_reason' => 'Не определён профиль обязательных документов',
                'missing_requirements' => [],
            ];
        }

        $presentCodes = [];
        foreach ($links as $link) {
            if (!empty($link['document_deleted_at'])) {
                continue;
            }
            $code = trim((string) ($link['requirement_code'] ?? ''));
            if ($code !== '') {
                $presentCodes[$code] = true;
            }
        }

        $missing = [];
        foreach ($profile['requirements'] as $requirement) {
            $satisfied = false;
            foreach ($requirement['accepted'] as $acceptedCode) {
                if (isset($presentCodes[$acceptedCode])) {
                    $satisfied = true;
                    break;
                }
            }
            if (!$satisfied) {
                $missing[] = $requirement;
            }
        }

        return [
            'is_problem' => $missing !== [],
            'problem_reason' => $missing !== [] ? 'Не хватает обязательных документов' : null,
            'missing_requirements' => $missing,
        ];
    }

    public static function counterpartyOptions(PDO $pdo): array
    {
        $options = [];
        foreach ($pdo->query('SELECT id, name, inn, entity_type FROM clients ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $options[] = [
                'entity_type' => 'client',
                'entity_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'inn' => (string) ($row['inn'] ?? ''),
                'legal_type' => (string) ($row['entity_type'] ?? ''),
            ];
        }
        foreach ($pdo->query('SELECT id, name, inn, contractor_type FROM contractors ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $options[] = [
                'entity_type' => 'contractor',
                'entity_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'inn' => (string) ($row['inn'] ?? ''),
                'legal_type' => (string) ($row['contractor_type'] ?? ''),
            ];
        }
        return $options;
    }

    public static function assignCounterparty(PDO $pdo, int $transactionId, string $entityType, int $entityId, int $userId): void
    {
        self::assertTransactionInScope($pdo, $transactionId);
        self::loadEntity($pdo, $entityType, $entityId);

        $stmt = $pdo->prepare(
            'INSERT INTO finance_closing_counterparties
                (bank_transaction_id, entity_type, entity_id, assigned_by_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                entity_type = VALUES(entity_type),
                entity_id = VALUES(entity_id),
                assigned_by_user_id = VALUES(assigned_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$transactionId, $entityType, $entityId, $userId > 0 ? $userId : null]);
    }

    public static function linkExistingDocument(PDO $pdo, int $transactionId, int $documentId, string $requirementCode, int $userId): void
    {
        self::assertTransactionInScope($pdo, $transactionId);
        $row = self::findReportRow($pdo, $transactionId);
        if ($row === null || empty($row['counterparty'])) {
            throw new RuntimeException('Сначала определите контрагента');
        }

        self::assertRequirementAllowed($row['profile'], $requirementCode);
        $document = self::documentForCounterparty($pdo, $documentId, $row['counterparty']);
        if (!$document) {
            throw new RuntimeException('Документ не найден у выбранного контрагента');
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO finance_closing_document_links
                (bank_transaction_id, document_id, requirement_code, created_by_user_id)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $documentId, $requirementCode, $userId > 0 ? $userId : null]);
    }

    public static function unlinkDocument(PDO $pdo, int $transactionId, int $linkId): void
    {
        self::assertTransactionInScope($pdo, $transactionId);
        $stmt = $pdo->prepare('DELETE FROM finance_closing_document_links WHERE id = ? AND bank_transaction_id = ?');
        $stmt->execute([$linkId, $transactionId]);
    }

    public static function documentsForCounterparty(PDO $pdo, string $entityType, int $entityId): array
    {
        if (!in_array($entityType, ['client', 'contractor'], true) || $entityId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT d.id, d.original_name, d.document_type, d.created_at,
                    dt.name AS type_name, dt.code AS type_code
             FROM documents d
             LEFT JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.entity_type = ? AND d.entity_id = ? AND d.deleted_at IS NULL
             ORDER BY d.created_at DESC, d.id DESC'
        );
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function uploadAndLink(
        PDO $pdo,
        int $companyId,
        int $transactionId,
        string $requirementCode,
        array $file,
        int $userId,
        string $roleCode
    ): int {
        self::assertTransactionInScope($pdo, $transactionId);
        $row = self::findReportRow($pdo, $transactionId);
        if ($row === null || empty($row['counterparty'])) {
            throw new RuntimeException('Сначала определите контрагента');
        }
        self::assertRequirementAllowed($row['profile'], $requirementCode);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Файл не загружен');
        }
        $maxSize = function_exists('legalEntityDocumentMaxFileSize') ? legalEntityDocumentMaxFileSize() : 20 * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxSize) {
            throw new RuntimeException('Недопустимый размер файла');
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = function_exists('legalEntityDocumentAllowedExtensions')
            ? legalEntityDocumentAllowedExtensions()
            : ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Недопустимый тип файла');
        }

        $counterparty = $row['counterparty'];
        $relativeDir = 'companies/' . $companyId . '/documents/' . $counterparty['entity_type'] . '/' . $counterparty['entity_id'];
        $absoluteDir = storage_path($relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Не удалось подготовить каталог документов');
        }

        $storedName = uniqid('doc_', true) . '.' . $extension;
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Не удалось сохранить файл');
        }

        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO documents
                    (entity_type, entity_id, document_type, original_name, stored_name, relative_path,
                     mime_type, file_size, status, uploaded_by_user_id, uploaded_by_role,
                     created_by_user_id, created_by_role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $counterparty['entity_type'], $counterparty['entity_id'], self::REQUIREMENT_LABELS[$requirementCode],
                (string) $file['name'], $storedName, $relativeDir . '/' . $storedName,
                (string) ($file['type'] ?? 'application/octet-stream'), (int) $file['size'], 'uploaded',
                $userId > 0 ? $userId : null, $roleCode, $userId > 0 ? $userId : null, $roleCode,
            ]);
            $documentId = (int) $pdo->lastInsertId();
            $link = $pdo->prepare(
                'INSERT INTO finance_closing_document_links
                    (bank_transaction_id, document_id, requirement_code, created_by_user_id)
                 VALUES (?, ?, ?, ?)'
            );
            $link->execute([$transactionId, $documentId, $requirementCode, $userId > 0 ? $userId : null]);
            $pdo->commit();
            return $documentId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            throw $e;
        }
    }

    private static function loadLinksByTransaction(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT l.id, l.bank_transaction_id, l.document_id, l.requirement_code,
                    d.original_name, d.document_type, d.deleted_at AS document_deleted_at,
                    d.entity_type AS document_entity_type, d.entity_id AS document_entity_id
             FROM finance_closing_document_links l
             INNER JOIN documents d ON d.id = l.document_id
             ORDER BY l.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['bank_transaction_id']][] = $row;
        }
        return $grouped;
    }

    private static function resolveCounterparty(PDO $pdo, array $row, array &$cache): ?array
    {
        $assignedType = trim((string) ($row['assigned_entity_type'] ?? ''));
        $assignedId = (int) ($row['assigned_entity_id'] ?? 0);
        if ($assignedType !== '' && $assignedId > 0) {
            return self::loadEntityCached($pdo, $assignedType, $assignedId, $cache);
        }

        $inn = preg_replace('/\D+/', '', (string) ($row['counterparty_inn'] ?? '')) ?? '';
        if ($inn === '') {
            return null;
        }

        $matches = [];
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE REPLACE(REPLACE(inn, " ", ""), "-", "") = ?');
        $stmt->execute([$inn]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $entity = self::loadEntityCached($pdo, 'client', (int) $id, $cache);
            if ($entity) {
                $matches[] = $entity;
            }
        }
        $stmt = $pdo->prepare('SELECT id FROM contractors WHERE REPLACE(REPLACE(inn, " ", ""), "-", "") = ?');
        $stmt->execute([$inn]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $entity = self::loadEntityCached($pdo, 'contractor', (int) $id, $cache);
            if ($entity) {
                $matches[] = $entity;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function loadEntityCached(PDO $pdo, string $entityType, int $entityId, array &$cache): ?array
    {
        $key = $entityType . ':' . $entityId;
        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = self::loadEntity($pdo, $entityType, $entityId);
            } catch (RuntimeException) {
                $cache[$key] = null;
            }
        }
        return $cache[$key];
    }

    private static function loadEntity(PDO $pdo, string $entityType, int $entityId): array
    {
        if ($entityType === 'client') {
            $stmt = $pdo->prepare('SELECT id, name, inn, entity_type AS legal_type FROM clients WHERE id = ? LIMIT 1');
        } elseif ($entityType === 'contractor') {
            $stmt = $pdo->prepare('SELECT id, name, inn, contractor_type AS legal_type FROM contractors WHERE id = ? LIMIT 1');
        } else {
            throw new RuntimeException('Недопустимый тип контрагента');
        }
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entity) {
            throw new RuntimeException('Контрагент не найден');
        }
        $entity['entity_type'] = $entityType;
        $entity['entity_id'] = (int) $entity['id'];
        return $entity;
    }

    private static function documentForCounterparty(PDO $pdo, int $documentId, array $counterparty): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM documents
             WHERE id = ? AND entity_type = ? AND entity_id = ? AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$documentId, $counterparty['entity_type'], $counterparty['entity_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function assertRequirementAllowed(?array $profile, string $requirementCode): void
    {
        if ($profile === null) {
            throw new RuntimeException('Не определён профиль обязательных документов');
        }
        foreach ($profile['requirements'] as $requirement) {
            if (in_array($requirementCode, $requirement['accepted'], true)) {
                return;
            }
        }
        throw new RuntimeException('Этот тип документа не входит в обязательный профиль операции');
    }

    private static function assertTransactionInScope(PDO $pdo, int $transactionId): void
    {
        if ($transactionId <= 0) {
            throw new RuntimeException('Некорректная операция');
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM bank_transactions bt
             WHERE bt.id = ?
               AND COALESCE(bt.is_internal_transfer, 0) = 0
               AND NOT EXISTS (
                    SELECT 1 FROM finance_employee_movements fem
                    WHERE fem.bank_transaction_id = bt.id
               )
             LIMIT 1'
        );
        $stmt->execute([$transactionId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Операция не входит в контур закрывающих документов');
        }
    }

    private static function findReportRow(PDO $pdo, int $transactionId): ?array
    {
        foreach (self::reportRows($pdo, false) as $row) {
            if ((int) $row['id'] === $transactionId) {
                return $row;
            }
        }
        return null;
    }
}
