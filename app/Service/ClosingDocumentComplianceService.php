<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class ClosingDocumentComplianceService
{
    public static function schemaReady(PDO $pdo): bool
    {
        if (!ClosingDocumentService::schemaReady($pdo)) return false;
        try {
            $pdo->query('SELECT is_edo FROM finance_closing_document_links LIMIT 1')->fetchColumn();
            $pdo->query('SELECT requirement_key FROM finance_closing_document_manual_statuses LIMIT 1')->fetchColumn();
            $pdo->query('SELECT requirement_key FROM finance_closing_document_requirement_exceptions LIMIT 1')->fetchColumn();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function profileCodeForBankName(?string $name): string
    {
        $upper = mb_strtoupper(trim((string)$name), 'UTF-8');
        if (preg_match('/(?:^|[^\p{L}\p{N}])(?:ООО|АО|ЗАО)(?:$|[^\p{L}\p{N}])/u', $upper)) {
            return 'legal_entity';
        }
        if (
            preg_match('/(?:^|[^\p{L}\p{N}])ИП(?:$|[^\p{L}\p{N}])/u', $upper)
            || preg_match('/(?:^|[^\p{L}\p{N}])ИНДИВИДУАЛЬНЫЙ\s+ПРЕДПРИНИМАТЕЛЬ(?:$|[^\p{L}\p{N}])/u', $upper)
        ) {
            return 'individual';
        }
        return 'self_employed';
    }

    public static function reportRows(PDO $pdo, bool $problemOnly = false): array
    {
        if (!self::schemaReady($pdo)) return [];

        $baseRows = ClosingDocumentService::reportRows($pdo, false);
        $edoByLink = self::edoByLink($pdo);
        $manualByTransaction = self::manualStatusMap($pdo);
        $exceptionsByInn = self::requirementExceptionMap($pdo);
        $result = [];

        foreach ($baseRows as $row) {
            $transactionId = (int)($row['id'] ?? 0);
            $inn = ClosingDocumentService::normalizeInn($row['counterparty_inn'] ?? '');
            $direction = (string)($row['direction'] ?? ClosingDocumentService::directionForAmounts(
                (float)($row['debit_amount'] ?? 0),
                (float)($row['credit_amount'] ?? 0)
            ));
            $profile = ClosingDocumentService::profileFor(
                self::profileCodeForBankName($row['counterparty_name'] ?? ''),
                $direction
            );

            $links = [];
            foreach (($row['links'] ?? []) as $link) {
                $link['is_edo'] = !empty($edoByLink[(int)($link['id'] ?? 0)]) ? 1 : 0;
                $links[] = $link;
            }

            $statuses = self::requirementStatuses(
                $profile,
                $links,
                $manualByTransaction[$transactionId] ?? [],
                $exceptionsByInn[$inn] ?? []
            );

            $isWholePaymentExempt = !empty($row['is_exempt']);
            $evaluation = $isWholePaymentExempt
                ? ['is_problem' => false, 'problem_reason' => null, 'missing_requirements' => []]
                : self::evaluateStatuses($statuses);

            $row['normalized_inn'] = $inn;
            $row['direction'] = $direction;
            $row['profile'] = $profile;
            $row['links'] = $links;
            $row['requirement_statuses'] = $statuses;
            $row['manual_requirement_statuses'] = $manualByTransaction[$transactionId] ?? [];
            $row['inn_requirement_exceptions'] = $exceptionsByInn[$inn] ?? [];
            $row['missing_requirements'] = $evaluation['missing_requirements'];
            $row['is_problem'] = $evaluation['is_problem'];
            $row['problem_reason'] = $evaluation['problem_reason'];

            if (!$problemOnly || $row['is_problem']) $result[] = $row;
        }

        return $result;
    }

    public static function problemCount(PDO $pdo): int
    {
        return count(self::reportRows($pdo, true));
    }

    public static function requirementStatuses(?array $profile, array $links, array $manualKeys, array $innExceptionKeys): array
    {
        $columns = ClosingDocumentService::requirementColumns();
        $statuses = [];
        foreach ($columns as $key => $label) {
            $statuses[$key] = [
                'key' => $key,
                'label' => $label,
                'applicable' => false,
                'satisfied' => false,
                'accepted' => [],
                'source' => null,
                'manual_collected' => !empty($manualKeys[$key]),
                'exempt_for_inn' => !empty($innExceptionKeys[$key]),
            ];
        }
        if ($profile === null) return $statuses;

        foreach (($profile['requirements'] ?? []) as $requirement) {
            $key = (string)($requirement['key'] ?? '');
            if ($key === '' || !isset($statuses[$key])) continue;
            $accepted = array_values($requirement['accepted'] ?? []);
            $exemptForInn = !empty($innExceptionKeys[$key]);
            $manualCollected = !empty($manualKeys[$key]);
            $hasFile = false;
            $hasEdo = false;

            foreach ($links as $link) {
                if (!empty($link['document_deleted_at'])) continue;
                $code = trim((string)($link['requirement_code'] ?? ''));
                if (!in_array($code, $accepted, true)) continue;
                $hasFile = true;
                if (!empty($link['is_edo'])) $hasEdo = true;
            }

            $source = $hasEdo ? 'edo' : ($hasFile ? 'file' : ($manualCollected ? 'manual' : null));
            $statuses[$key] = [
                'key' => $key,
                'label' => (string)($requirement['label'] ?? $columns[$key]),
                'applicable' => !$exemptForInn,
                'satisfied' => !$exemptForInn && ($hasFile || $manualCollected),
                'accepted' => $accepted,
                'source' => $source,
                'manual_collected' => $manualCollected,
                'exempt_for_inn' => $exemptForInn,
            ];
        }
        return $statuses;
    }

    public static function saveRequirementOverrides(PDO $pdo, int $transactionId, array $manualSelected, array $innExemptSelected, int $userId): void
    {
        if ($transactionId <= 0) throw new RuntimeException('Некорректная операция');
        $row = self::findRow($pdo, $transactionId);
        if ($row === null || !empty($row['is_exempt'])) throw new RuntimeException('Операция недоступна для ручной настройки документов');
        $inn = (string)($row['normalized_inn'] ?? '');
        $applicableKeys = [];
        foreach (($row['profile']['requirements'] ?? []) as $requirement) {
            $key = (string)($requirement['key'] ?? '');
            if ($key !== '') $applicableKeys[$key] = true;
        }
        if ($applicableKeys === []) throw new RuntimeException('Для операции не определён профиль документов');
        if ($inn === '' && $innExemptSelected !== []) throw new RuntimeException('Нельзя создать исключение по документу без ИНН');

        $pdo->beginTransaction();
        try {
            foreach (array_keys($applicableKeys) as $key) {
                if (!empty($manualSelected[$key])) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO finance_closing_document_manual_statuses (bank_transaction_id, requirement_key, updated_by_user_id) VALUES (?, ?, ?) '
                        . 'ON DUPLICATE KEY UPDATE updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP'
                    );
                    $stmt->execute([$transactionId, $key, $userId > 0 ? $userId : null]);
                } else {
                    $stmt = $pdo->prepare('DELETE FROM finance_closing_document_manual_statuses WHERE bank_transaction_id = ? AND requirement_key = ?');
                    $stmt->execute([$transactionId, $key]);
                }

                if ($inn !== '') {
                    if (!empty($innExemptSelected[$key])) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO finance_closing_document_requirement_exceptions (inn, requirement_key, created_by_user_id) VALUES (?, ?, ?) '
                            . 'ON DUPLICATE KEY UPDATE created_by_user_id = VALUES(created_by_user_id)'
                        );
                        $stmt->execute([$inn, $key, $userId > 0 ? $userId : null]);
                    } else {
                        $stmt = $pdo->prepare('DELETE FROM finance_closing_document_requirement_exceptions WHERE inn = ? AND requirement_key = ?');
                        $stmt->execute([$inn, $key]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function uploadAndLink(PDO $pdo, int $companyId, int $transactionId, string $requirementCode, array $file, int $userId, string $roleCode, bool $isEdo): int
    {
        if ($transactionId <= 0) throw new RuntimeException('Некорректная операция');
        $row = self::findRow($pdo, $transactionId);
        if ($row === null) throw new RuntimeException('Операция не входит в контур закрывающих документов');
        if (!empty($row['is_exempt'])) throw new RuntimeException('Для этой операции документы не требуются');

        $requirementCode = trim($requirementCode);
        $allowedCodes = [];
        foreach (($row['profile']['requirements'] ?? []) as $requirement) {
            foreach (($requirement['accepted'] ?? []) as $code) {
                $allowedCodes[(string)$code] = true;
            }
        }
        if ($requirementCode === '' || !isset($allowedCodes[$requirementCode])) {
            throw new RuntimeException('Этот тип документа не входит в обязательный профиль операции');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загружен');
        $maxSize = function_exists('legalEntityDocumentMaxFileSize') ? legalEntityDocumentMaxFileSize() : 20 * 1024 * 1024;
        if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxSize) throw new RuntimeException('Недопустимый размер файла');
        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExtensions = function_exists('legalEntityDocumentAllowedExtensions') ? legalEntityDocumentAllowedExtensions() : ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
        if (!in_array($extension, $allowedExtensions, true)) throw new RuntimeException('Недопустимый тип файла');

        $requirementLabels = ClosingDocumentService::requirementLabels();
        if (!isset($requirementLabels[$requirementCode])) throw new RuntimeException('Неизвестный тип документа');

        $relativeDir = 'companies/' . $companyId . '/documents/bank_transaction/' . $transactionId;
        $absoluteDir = storage_path($relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Не удалось подготовить каталог документов');
        }
        $storedName = uniqid('doc_', true) . '.' . $extension;
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string)($file['tmp_name'] ?? ''), $absolutePath)) {
            throw new RuntimeException('Не удалось сохранить файл');
        }

        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO documents (entity_type, entity_id, document_type, original_name, stored_name, relative_path, mime_type, file_size, status, uploaded_by_user_id, uploaded_by_role, created_by_user_id, created_by_role) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                'bank_transaction',
                $transactionId,
                $requirementLabels[$requirementCode],
                (string)($file['name'] ?? ''),
                $storedName,
                $relativeDir . '/' . $storedName,
                (string)($file['type'] ?? 'application/octet-stream'),
                (int)$file['size'],
                'uploaded',
                $userId > 0 ? $userId : null,
                $roleCode,
                $userId > 0 ? $userId : null,
                $roleCode,
            ]);
            $documentId = (int)$pdo->lastInsertId();
            $link = $pdo->prepare(
                'INSERT INTO finance_closing_document_links (bank_transaction_id, document_id, requirement_code, is_edo, created_by_user_id) VALUES (?, ?, ?, ?, ?)'
            );
            $link->execute([$transactionId, $documentId, $requirementCode, $isEdo ? 1 : 0, $userId > 0 ? $userId : null]);
            $pdo->commit();
            return $documentId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_file($absolutePath)) @unlink($absolutePath);
            throw $e;
        }
    }

    private static function evaluateStatuses(array $statuses): array
    {
        $missing = [];
        foreach ($statuses as $status) {
            if (empty($status['applicable'])) continue;
            if (empty($status['satisfied'])) {
                $missing[] = ['key' => $status['key'], 'label' => $status['label'], 'accepted' => $status['accepted']];
            }
        }
        return [
            'is_problem' => $missing !== [],
            'problem_reason' => $missing !== [] ? 'Не хватает обязательных документов' : null,
            'missing_requirements' => $missing,
        ];
    }

    private static function edoByLink(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, is_edo FROM finance_closing_document_links')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) $map[(int)$row['id']] = !empty($row['is_edo']);
        return $map;
    }

    private static function manualStatusMap(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT bank_transaction_id, requirement_key FROM finance_closing_document_manual_statuses')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) $map[(int)$row['bank_transaction_id']][(string)$row['requirement_key']] = true;
        return $map;
    }

    private static function requirementExceptionMap(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT inn, requirement_key FROM finance_closing_document_requirement_exceptions')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $inn = ClosingDocumentService::normalizeInn($row['inn'] ?? '');
            if ($inn !== '') $map[$inn][(string)$row['requirement_key']] = true;
        }
        return $map;
    }

    private static function findRow(PDO $pdo, int $transactionId): ?array
    {
        foreach (self::reportRows($pdo, false) as $row) {
            if ((int)($row['id'] ?? 0) === $transactionId) return $row;
        }
        return null;
    }
}
