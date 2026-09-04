<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

trait DocumentGeneratorTemplateTrait
{
    private const REQUIRED_TABLES = ['document_templates', 'document_template_versions', 'generated_documents', 'generated_document_requests'];
    private static array $builtInTemplateWarnings = [];

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            foreach (self::REQUIRED_TABLES as $table) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $stmt->execute([$table]);
                if ((int)$stmt->fetchColumn() !== 1) return false;
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function seedDefaultTemplates(PDO $pdo, int $companyId, int $userId, string $role): void
    {
        self::$builtInTemplateWarnings = [];
        if (!self::schemaReady($pdo)) throw new RuntimeException('Схема генератора документов ещё не установлена.');
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'planex_docgen_seed_' . substr(hash('sha256', $database), 0, 24);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Не удалось получить блокировку инициализации шаблонов документов.');
        }

        try {
            $defaults = [
                'vehicle_lease_crew' => ['Договор-заявка аренды ТС с экипажем', 'contract_vehicle_lease_crew_v3.docx'],
                'waybill_driver_assignment' => ['Приложение к путевому листу / задание водителю', 'waybill_driver_assignment_v1.docx'],
            ];
            foreach ($defaults as $type => [$name, $filename]) {
                $stmt = $pdo->prepare('SELECT id FROM document_templates WHERE document_type=? ORDER BY id LIMIT 1');
                $stmt->execute([$type]);
                if ((int)$stmt->fetchColumn() > 0) continue;
                try {
                    $source = self::materializeBuiltInTemplate($filename);
                } catch (RuntimeException $e) {
                    self::$builtInTemplateWarnings[] = [
                        'document_type' => $type,
                        'name' => $name,
                        'filename' => $filename,
                        'message' => $e->getMessage(),
                    ];
                    continue;
                }
                try {
                    self::createTemplateVersionFromFile($pdo, $companyId, null, $type, $name, $source, $filename, $userId, $role);
                } finally {
                    @unlink($source);
                }
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    public static function builtInTemplateWarnings(): array
    {
        return self::$builtInTemplateWarnings;
    }

    private static function materializeBuiltInTemplate(string $filename): string
    {
        $expected = [
            'contract_vehicle_lease_crew_v1.docx' => '0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
            'contract_vehicle_lease_crew_v2.docx' => '7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',
            'contract_vehicle_lease_crew_v3.docx' => '87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',
            'waybill_driver_assignment_v1.docx' => '5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
        ];
        if (!isset($expected[$filename])) {
            throw new RuntimeException('Неизвестный встроенный DOCX-шаблон: ' . $filename);
        }
        $parts = glob(base_path('resources/document-templates/' . $filename . '.b64.part*')) ?: [];
        sort($parts, SORT_STRING);
        if ($parts === []) {
            throw new RuntimeException('Не найдены части встроенного DOCX-шаблона: ' . $filename);
        }
        $bytes = '';
        foreach ($parts as $part) {
            $chunk = file_get_contents($part);
            if ($chunk === false) throw new RuntimeException('Не удалось прочитать часть встроенного DOCX-шаблона.');
            $encoded = preg_replace('/\s+/', '', $chunk) ?? '';
            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || $decoded === '') {
                throw new RuntimeException('Встроенный DOCX-шаблон повреждён: base64 part decode failed.');
            }
            $bytes .= $decoded;
        }
        if ($bytes === '') {
            throw new RuntimeException('Встроенный DOCX-шаблон повреждён: empty materialized content.');
        }
        $actual = hash('sha256', $bytes);
        if (!hash_equals($expected[$filename], $actual)) {
            throw new RuntimeException('Встроенный DOCX-шаблон повреждён: SHA-256 mismatch.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'planex_docgen_builtin_');
        if ($tmp === false) throw new RuntimeException('Не удалось создать временный файл встроенного шаблона.');
        $docx = $tmp . '.docx';
        @unlink($tmp);
        if (file_put_contents($docx, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($docx);
            throw new RuntimeException('Не удалось материализовать встроенный DOCX-шаблон.');
        }
        return $docx;
    }

    public static function listTemplates(PDO $pdo, bool $activeOnly = false): array
    {
        $sql = "SELECT t.*,v.version_no,v.original_name,v.stored_path,v.file_size,v.sha256,v.placeholders_json,v.created_at version_created_at
                FROM document_templates t
                LEFT JOIN document_template_versions v ON v.id=t.active_version_id";
        if ($activeOnly) $sql .= " WHERE t.status='active' AND t.active_version_id IS NOT NULL";
        $sql .= ' ORDER BY t.document_type,t.name,t.id';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listTemplateVersions(PDO $pdo, int $templateId): array
    {
        $stmt = $pdo->prepare(
            'SELECT v.*,t.name template_name,t.document_type,t.status template_status,t.active_version_id
               FROM document_template_versions v
               JOIN document_templates t ON v.template_id=t.id
              WHERE v.template_id=?
              ORDER BY v.version_no DESC,v.id DESC'
        );
        $stmt->execute([$templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getTemplateVersion(PDO $pdo, int $templateId, ?int $versionId = null, bool $requireActiveTemplate = false): ?array
    {
        $sql = "SELECT t.id template_id,t.name template_name,t.document_type,t.status template_status,t.active_version_id,
                       v.id version_id,v.version_no,v.original_name,v.stored_path,v.file_size,v.sha256,v.placeholders_json,v.created_at
                FROM document_templates t JOIN document_template_versions v ON v.template_id=t.id
                WHERE t.id=?";
        $params = [$templateId];
        if ($versionId !== null && $versionId > 0) { $sql .= ' AND v.id=?'; $params[] = $versionId; }
        else $sql .= ' AND v.id=t.active_version_id';
        if ($requireActiveTemplate) $sql .= " AND t.status='active'";
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function createTemplateVersionFromUpload(
        PDO $pdo,
        int $companyId,
        ?int $templateId,
        string $documentType,
        string $name,
        array $file,
        int $userId,
        string $role
    ): int {
        $validation = DocumentTemplateService::validateUploadedDocx($file);
        if (!($validation['ok'] ?? false)) throw new RuntimeException(implode(' ', $validation['errors'] ?? ['Некорректный DOCX.']));
        return self::createTemplateVersionFromFile(
            $pdo, $companyId, $templateId, $documentType, $name,
            (string)$file['tmp_name'], (string)$file['name'], $userId, $role
        );
    }

    public static function createTemplateVersionFromFile(
        PDO $pdo,
        int $companyId,
        ?int $templateId,
        string $documentType,
        string $name,
        string $sourcePath,
        string $originalName,
        int $userId,
        string $role
    ): int {
        if (!DocumentTemplateService::validateDocumentType($documentType)) throw new RuntimeException('Неизвестный тип документа.');
        $name = trim($name);
        if ($name === '') throw new RuntimeException('Укажите название шаблона.');
        $validation = DocumentTemplateService::validateDocxFile($sourcePath);
        if (!($validation['ok'] ?? false)) throw new RuntimeException(implode(' ', $validation['errors'] ?? ['Некорректный DOCX.']));
        if (($validation['repeat_rows'] ?? 0) !== 1) throw new RuntimeException('Для текущих типов документов шаблон должен содержать одну repeat-строку {{request.*}}.');

        $pdo->beginTransaction();
        $storedPath = null;
        try {
            if ($templateId !== null && $templateId > 0) {
                $stmt = $pdo->prepare('SELECT id,document_type FROM document_templates WHERE id=? FOR UPDATE');
                $stmt->execute([$templateId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) throw new RuntimeException('Шаблон не найден.');
                if ((string)$existing['document_type'] !== $documentType) throw new RuntimeException('Тип существующего шаблона нельзя менять новой версией.');
                $templateId = (int)$existing['id'];
                $rename = $pdo->prepare('UPDATE document_templates SET name=?,updated_by_user_id=?,updated_by_role=? WHERE id=?');
                $rename->execute([$name,$userId,$role,$templateId]);
            } else {
                $insert = $pdo->prepare("INSERT INTO document_templates(name,document_type,status,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES(?,?,'active',?,?,?,?)");
                $insert->execute([$name,$documentType,$userId,$role,$userId,$role]);
                $templateId = (int)$pdo->lastInsertId();
            }

            $v = $pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM document_template_versions WHERE template_id=?');
            $v->execute([$templateId]);
            $versionNo = (int)$v->fetchColumn();
            $safeOriginal = DocumentService::safeDownloadName($originalName);
            $storedName = sprintf('v%03d_%s.docx', $versionNo, bin2hex(random_bytes(8)));
            $relativeDir = sprintf('companies/%d/document-generator/templates/%d', $companyId, $templateId);
            $absoluteDir = storage_path($relativeDir);
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) throw new RuntimeException('Не удалось создать каталог шаблонов.');
            $storedPath = $relativeDir . '/' . $storedName;
            $absolute = storage_path($storedPath);
            if (!copy($sourcePath, $absolute)) throw new RuntimeException('Не удалось сохранить DOCX-шаблон.');

            $insertVersion = $pdo->prepare(
                'INSERT INTO document_template_versions(template_id,version_no,original_name,stored_path,file_size,sha256,placeholders_json,created_by_user_id,created_by_role)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            );
            $insertVersion->execute([
                $templateId,$versionNo,$safeOriginal,$storedPath,(int)filesize($absolute),
                (string)($validation['sha256'] ?? hash_file('sha256',$absolute)),
                self::json($validation['placeholders'] ?? []),$userId,$role,
            ]);
            $versionId = (int)$pdo->lastInsertId();
            $activate = $pdo->prepare("UPDATE document_templates SET active_version_id=?,status='active',updated_by_user_id=?,updated_by_role=? WHERE id=?");
            $activate->execute([$versionId,$userId,$role,$templateId]);
            $pdo->commit();
            return $versionId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($storedPath !== null) @unlink(storage_path($storedPath));
            throw $e;
        }
    }

    public static function setTemplateActive(PDO $pdo, int $templateId, bool $active, int $userId, string $role): void
    {
        $stmt = $pdo->prepare("UPDATE document_templates SET status=?,updated_by_user_id=?,updated_by_role=? WHERE id=?");
        $stmt->execute([$active ? 'active' : 'inactive',$userId,$role,$templateId]);
        if ($stmt->rowCount() < 1) {
            $check=$pdo->prepare('SELECT id FROM document_templates WHERE id=?');$check->execute([$templateId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Шаблон не найден.');
        }
    }

    public static function listAccessibleExecutors(PDO $pdo, int $userId, string $role): array
    {
        $sql = self::executorSelectSql() . self::activeExecutorWhere() . ' ORDER BY ct.name,d.full_name,c.id';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (in_array($role, ['company_owner','senior_logist'], true)) return $rows;
        if ($role !== 'logist' || $userId <= 0) return [];
        return array_values(array_filter($rows, static fn(array $row): bool => AccessControlService::hasRouteExecutorAccess($pdo,$row,$userId,'view')));
    }

    public static function loadAccessibleExecutor(PDO $pdo, int $crewId, int $userId, string $role): ?array
    {
        $stmt=$pdo->prepare(self::executorSelectSql() . self::activeExecutorWhere() . ' AND c.id=? LIMIT 1');
        $stmt->execute([$crewId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        if (in_array($role,['company_owner','senior_logist'],true)) return $row;
        if ($role==='logist' && $userId>0 && AccessControlService::hasRouteExecutorAccess($pdo,$row,$userId,'view')) return $row;
        return null;
    }
}
