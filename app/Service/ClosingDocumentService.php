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
    ];

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
    ];

    private const REQUIREMENT_LABELS = [
        'agreement' => 'Договор',
        'act' => 'Акт',
        'upd' => 'УПД',
        'npd_receipt' => 'Чек НПД',
    ];

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM finance_closing_document_links LIMIT 1')->fetchColumn();
            $pdo->query('SELECT 1 FROM finance_closing_document_exceptions LIMIT 1')->fetchColumn();
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

    public static function normalizeInn(?string $inn): string
    {
        return preg_replace('/\D+/', '', (string) $inn) ?? '';
    }

    /**
     * OWNER V2 rule: use the recipient name exactly as received from bank data.
     * ООО => legal entity; ИП => individual entrepreneur; otherwise => NPD/self-employed.
     */
    public static function profileCodeForBankName(?string $name): string
    {
        $upper = mb_strtoupper(trim((string) $name), 'UTF-8');
        if (preg_match('/(?:^|[^\p{L}\p{N}])ООО(?:$|[^\p{L}\p{N}])/u', $upper)) {
            return 'legal_entity';
        }
        if (preg_match('/(?:^|[^\p{L}\p{N}])ИП(?:$|[^\p{L}\p{N}])/u', $upper)) {
            return 'individual';
        }
        return 'self_employed';
    }

    /** SINGLE SOURCE OF TRUTH for report rows, problem filter and sidebar badge. */
    public static function reportRows(PDO $pdo, bool $problemOnly = false): array
    {
        if (!self::schemaReady($pdo)) {
            return [];
        }

        $rows = $pdo->query(
            "SELECT bt.id, bt.account_id, bt.operation_date, bt.document_number,
                    bt.operation_type, bt.counterparty_name, bt.counterparty_inn,
                    bt.debit_amount, bt.credit_amount, bt.purpose,
                    bt.classification_status, COALESCE(bt.is_internal_transfer, 0) AS is_internal_transfer
             FROM bank_transactions bt
             WHERE COALESCE(bt.debit_amount, 0) > 0
               AND COALESCE(bt.is_internal_transfer, 0) = 0
               AND NOT EXISTS (
                    SELECT 1 FROM finance_employee_movements fem
                    WHERE fem.bank_transaction_id = bt.id
               )
             ORDER BY bt.operation_date DESC, bt.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $linksByTransaction = self::loadLinksByTransaction($pdo);
        $exceptions = self::exceptionMap($pdo);
        $result = [];

        foreach ($rows as $row) {
            $inn = self::normalizeInn($row['counterparty_inn'] ?? '');
            $profileCode = self::profileCodeForBankName($row['counterparty_name'] ?? '');
            $profile = self::profileFor($profileCode);
            $links = $linksByTransaction[(int) $row['id']] ?? [];
            $isExempt = $inn !== '' && isset($exceptions[$inn]);
            $evaluation = $isExempt
                ? ['is_problem' => false, 'problem_reason' => null, 'missing_requirements' => []]
                : self::evaluateProblem($profile, $links);

            $row['normalized_inn'] = $inn;
            $row['profile'] = $profile;
            $row['links'] = $links;
            $row['is_exempt'] = $isExempt;
            $row['exception_comment'] = $isExempt ? (string) ($exceptions[$inn]['comment'] ?? '') : '';
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

    public static function evaluateProblem(?array $profile, array $links): array
    {
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

    public static function exceptions(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT e.id, e.inn, e.comment, e.active, e.created_at, e.updated_at,
                    (SELECT bt.counterparty_name
                     FROM bank_transactions bt
                     WHERE REPLACE(REPLACE(REPLACE(bt.counterparty_inn, ' ', ''), '-', ''), '.', '') = e.inn
                     ORDER BY bt.operation_date DESC, bt.id DESC LIMIT 1) AS counterparty_name
             FROM finance_closing_document_exceptions e
             ORDER BY e.active DESC, e.inn ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function addException(PDO $pdo, string $inn, string $comment, int $userId): void
    {
        $inn = self::normalizeInn($inn);
        if (!in_array(strlen($inn), [10, 12], true)) {
            throw new RuntimeException('ИНН должен содержать 10 или 12 цифр');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO finance_closing_document_exceptions (inn, comment, active, created_by_user_id)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE comment = VALUES(comment), active = 1, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$inn, trim($comment) !== '' ? trim($comment) : null, $userId > 0 ? $userId : null]);
    }

    public static function removeException(PDO $pdo, int $id): void
    {
        if ($id <= 0) {
            throw new RuntimeException('Некорректное правило исключения');
        }
        $stmt = $pdo->prepare('DELETE FROM finance_closing_document_exceptions WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function existingDocumentsForInn(PDO $pdo, string $inn): array
    {
        $inn = self::normalizeInn($inn);
        if ($inn === '') {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT DISTINCT d.id, d.original_name, d.document_type, d.created_at,
                    dt.name AS type_name, dt.code AS type_code
             FROM finance_closing_document_links l
             INNER JOIN bank_transactions bt ON bt.id = l.bank_transaction_id
             INNER JOIN documents d ON d.id = l.document_id AND d.deleted_at IS NULL
             LEFT JOIN document_types dt ON dt.id = d.document_type_id
             WHERE REPLACE(REPLACE(REPLACE(bt.counterparty_inn, ' ', ''), '-', ''), '.', '') = ?
             ORDER BY d.created_at DESC, d.id DESC"
        );
        $stmt->execute([$inn]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function linkExistingDocument(PDO $pdo, int $transactionId, int $documentId, string $requirementCode, int $userId): void
    {
        self::assertTransactionInScope($pdo, $transactionId);
        $row = self::findReportRow($pdo, $transactionId);
        if ($row === null || !empty($row['is_exempt'])) {
            throw new RuntimeException('Для этой операции документы не требуются');
        }
        self::assertRequirementAllowed($row['profile'], $requirementCode);
        if (!self::documentReusableForInn($pdo, $documentId, (string) $row['normalized_inn'])) {
            throw new RuntimeException('Документ не найден среди документов этого ИНН');
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
        if ($row === null || !empty($row['is_exempt'])) {
            throw new RuntimeException('Для этой операции документы не требуются');
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

        $relativeDir = 'companies/' . $companyId . '/documents/bank_transaction/' . $transactionId;
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
                'bank_transaction', $transactionId, self::REQUIREMENT_LABELS[$requirementCode],
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

    private static function exceptionMap(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, inn, comment FROM finance_closing_document_exceptions WHERE active = 1')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $inn = self::normalizeInn($row['inn'] ?? '');
            if ($inn !== '') {
                $map[$inn] = $row;
            }
        }
        return $map;
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

    private static function documentReusableForInn(PDO $pdo, int $documentId, string $inn): bool
    {
        $inn = self::normalizeInn($inn);
        if ($documentId <= 0 || $inn === '') {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM finance_closing_document_links l
             INNER JOIN bank_transactions bt ON bt.id = l.bank_transaction_id
             INNER JOIN documents d ON d.id = l.document_id AND d.deleted_at IS NULL
             WHERE d.id = ?
               AND REPLACE(REPLACE(REPLACE(bt.counterparty_inn, ' ', ''), '-', ''), '.', '') = ?
             LIMIT 1"
        );
        $stmt->execute([$documentId, $inn]);
        return (bool) $stmt->fetchColumn();
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
               AND COALESCE(bt.debit_amount, 0) > 0
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
