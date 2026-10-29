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
                'vehicle_lease_crew' => ['Договор-заявка аренды ТС с экипажем', 'contract_vehicle_lease_crew_v9.docx'],
                'carrier_sublease_crew' => ['Договор-заявка субаренды ТС с экипажем', 'contract_carrier_sublease_crew_v2.docx'],
                'waybill_driver_assignment' => ['Приложение к путевому листу / задание водителю', 'waybill_driver_assignment_v1.docx'],
                'client_sublease_crew' => ['Договор субаренды транспортного средства с экипажем', 'client_sublease_contract_v1.docx'],
                'client_invoice' => ['Счёт клиенту', 'client_invoice_v1.docx'],
                'client_act' => ['Акт клиенту', 'client_act_v1.docx'],
                'client_upd_contract' => ['Договор клиенту для комплекта с УПД', 'client_upd_contract_v9.docx'],
                'client_upd_invoice' => ['Счёт клиенту для комплекта с УПД', 'client_upd_invoice_v5.docx'],
                'client_upd_status2' => ['УПД клиенту, статус 2', 'client_upd_status2_v9.docx'],
            ];
            foreach ($defaults as $type => [$name, $filename]) {
                $stmt = $pdo->prepare('SELECT t.id,t.name,v.sha256 FROM document_templates t LEFT JOIN document_template_versions v ON v.id=t.active_version_id WHERE t.document_type=? ORDER BY t.id LIMIT 1');
                $stmt->execute([$type]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existing !== null) {
                    // Upgrade only the known active version; preserve other owner-uploaded versions.
                    $previousBuiltInSha = [
                        'vehicle_lease_crew' => [
                            '87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',
                            '11c72fa3206d86d490f71aa88c47fb79cde9e06e0774c12793c89f9948f33e43',
                            'e3fab5f48b3de28d298f578ebd7aef8f99214b51fef4fa502200d5e5e02e5d1d',
                        ],
                        'carrier_sublease_crew' => ['074d6a082e1a07b823bf156814842e3cb345f1724bca1656e13e8be0d053719e'],
                        'client_upd_contract' => ['8da38048a57479485655ba115006428748221c0b0ce9d02ec66f09ef873150e9'],
                        'client_upd_invoice' => ['10b5f47f51e50d894475f2360dd921f3104ddd0ea048e4c64f27f93ff189e2dd', '6b82b0509cd621e591b818bc31931bc3c50885c0d4565b439ba98fb848e9b1b0'],
                        'client_upd_status2' => ['ef01e14c54913c2c140674ed04ecaff14b832521c3bbd8105127c3c5443ec312', 'f13cc1b92512d5a19d7fe31fc1fa7847e0688ef4daa3b6f41da2afb5f365e203', '89666528c3b539faec1c5f7481f424138fb8362972e7edefdfe811e573a0b9f1'],
                    ];
                    if (isset($previousBuiltInSha[$type]) && in_array((string)($existing['sha256'] ?? ''), $previousBuiltInSha[$type], true)) {
                        $source = self::materializeBuiltInTemplate($filename);
                        try {
                            self::createTemplateVersionFromFile($pdo, $companyId, (int)$existing['id'], $type, (string)$existing['name'], $source, $filename, $userId, $role);
                        } finally {
                            @unlink($source);
                        }
                    }
                    continue;
                }
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
            'contract_vehicle_lease_crew_v4.docx' => '11c72fa3206d86d490f71aa88c47fb79cde9e06e0774c12793c89f9948f33e43',
            'contract_vehicle_lease_crew_v5.docx' => '464074c526ec0d7a387d46c017ad359a94771163cb7a7f076954fc529a673535',
            'contract_vehicle_lease_crew_v9.docx' => 'e83efd9117faf61357fb4dfb9e105c4a989f7bbed9fe062d84355c18dc0df79f',
            'contract_carrier_sublease_crew_v1.docx' => '074d6a082e1a07b823bf156814842e3cb345f1724bca1656e13e8be0d053719e',
            'contract_carrier_sublease_crew_v2.docx' => 'b3f1068c42b9a9659f4774934eeb8924cf20c58e83e44a9298df98f3e35c0be7',
            'waybill_driver_assignment_v1.docx' => '5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
            'client_sublease_contract_v1.docx' => '01ebdc8bb56beb11b20abc787bd6067c7ae3379274e1143cf3e4cf1258f7230c',
            'client_upd_contract_v9.docx' => 'c61123bfbd56bcd2c648f348c4dfebbfc10cb1e5af099b563af5a78e3cb4fc87',
            'client_invoice_v1.docx' => '10b5f47f51e50d894475f2360dd921f3104ddd0ea048e4c64f27f93ff189e2dd',
            'client_upd_invoice_v4.docx' => '6b82b0509cd621e591b818bc31931bc3c50885c0d4565b439ba98fb848e9b1b0',
            'client_upd_invoice_v5.docx' => '4b0017c810e7fa10a6b8a1a9bcade9a2d67e5725f8a465ef7bccecf6e5c39935',
            'client_act_v1.docx' => '246b3394254d58a22d6ea492f9db56b2efcabef56d43de495766c2c902c6a1f9',
            'client_upd_status2_v1.docx' => 'cfb1669b79400014c2a3244e0c6f7c942213105938dc5253972115fdc88b91c6',
            'client_upd_status2_v8.docx' => '89666528c3b539faec1c5f7481f424138fb8362972e7edefdfe811e573a0b9f1',
            'client_upd_status2_v9.docx' => '54b3e16b5300c70f02dc02f173215224e45e1d1978f417fc6e98731da30bf421',
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

    private static function getUniqueActiveTemplateByType(PDO $pdo, string $documentType): array
    {
        $stmt = $pdo->prepare("SELECT t.id template_id,t.name template_name,t.document_type,t.status template_status,t.active_version_id,
                                     v.id version_id,v.version_no,v.original_name,v.stored_path,v.file_size,v.sha256,v.placeholders_json,v.created_at
                                FROM document_templates t
                                JOIN document_template_versions v ON v.id=t.active_version_id AND v.template_id=t.id
                               WHERE t.document_type=? AND t.status='active'
                               ORDER BY t.id");
        $stmt->execute([$documentType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) throw new RuntimeException('Нет активного шаблона: ' . (DocumentTemplateService::documentTypes()[$documentType] ?? $documentType) . '.');
        if (count($rows) !== 1) throw new RuntimeException('Для типа документа должен быть ровно один активный шаблон: ' . (DocumentTemplateService::documentTypes()[$documentType] ?? $documentType) . '.');
        return $rows[0];
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
        $repeatRows = (int)($validation['repeat_rows'] ?? 0);
        $expectedRepeatRows = in_array($documentType, ['client_invoice','client_act','client_upd_invoice','client_upd_status2'], true) ? 0 : 1;
        if ($repeatRows !== $expectedRepeatRows) {
            $message = $expectedRepeatRows === 1
                ? 'Для выбранного типа документа шаблон должен содержать одну repeat-строку {{request.*}}.'
                : 'Для выбранного типа документа repeat-строка {{request.*}} не используется.';
            throw new RuntimeException($message);
        }

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
        return RouteExecutorService::filterVisibleCrews($pdo, $rows);
    }

    public static function loadAccessibleExecutor(PDO $pdo, int $crewId, int $userId, string $role): ?array
    {
        $stmt=$pdo->prepare(self::executorSelectSql() . self::activeExecutorWhere() . ' AND c.id=? LIMIT 1');
        $stmt->execute([$crewId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        return RouteExecutorService::canAccessCrew($pdo, $crewId) ? $row : null;
    }
}
