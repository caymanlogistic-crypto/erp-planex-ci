<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/** Creates the three source entities and their crew in one tenant transaction. */
final class RouteExecutorCombinedCreateService
{
    public static function create(PDO $pdo, Database $centralDb, array $config, int $companyId, array $post, array $files, int $userId, string $role): int
    {
        $contractor = (array) ($post['contractor'] ?? []);
        $driver = (array) ($post['driver'] ?? []);
        $vehicle = (array) ($post['vehicle'] ?? []);
        $contractorService = new ContractorService($config, $centralDb);
        $driverService = new DriverService($config, $centralDb);
        $errors = $contractorService->validateContractor($contractor, $pdo);
        if ($errors) throw new RuntimeException('Подрядчик: ' . implode('; ', array_map('strval', $errors)));

        $fullName = preg_replace('/\s+/u', ' ', trim((string) ($driver['full_name'] ?? '')));
        if (count(preg_split('/\s+/u', $fullName ?: '') ?: []) !== 3) throw new RuntimeException('Укажите полное ФИО водителя.');
        $phone = self::phone((string) ($driver['phone'] ?? ''));
        if ($phone === null) throw new RuntimeException('Укажите телефон водителя в формате +7 900 000-00-00.');
        $extraPhones = (array) ($driver['extra_phones'] ?? []);
        foreach ($extraPhones as $extra) {
            if (self::phone((string) $extra) === null) throw new RuntimeException('Заполните каждый дополнительный телефон или удалите пустую строку.');
        }
        $driver['full_name'] = $fullName;
        $driver['phone'] = $phone;
        foreach (['passport_number', 'license_number'] as $field) {
            $raw = preg_replace('/\D+/', '', (string) ($driver[$field] ?? ''));
            $driver[$field] = $raw === '' ? null : (strlen($raw) === 10 ? substr($raw, 0, 4) . ' ' . substr($raw, 4) : throw new RuntimeException('Неверный формат номера паспорта или ВУ.'));
        }
        $department = preg_replace('/\D+/', '', (string) ($driver['passport_department_code'] ?? ''));
        $driver['passport_department_code'] = $department === '' ? null : (strlen($department) === 6 ? substr($department, 0, 3) . '-' . substr($department, 3) : throw new RuntimeException('Неверный код подразделения.'));
        $snils = preg_replace('/\D+/', '', (string) ($driver['snils'] ?? ''));
        $driver['snils'] = $snils === '' ? null : (strlen($snils) === 11 ? substr($snils, 0, 3) . '-' . substr($snils, 3, 3) . '-' . substr($snils, 6, 3) . ' ' . substr($snils, 9) : throw new RuntimeException('Неверный СНИЛС.'));
        foreach (['passport_issue_date', 'license_issue_date'] as $field) $driver[$field] = self::date((string) ($driver[$field] ?? ''));
        $driver['email'] = strtolower(trim((string) ($driver['email'] ?? '')));
        if ($driver['email'] !== '' && !filter_var($driver['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Неверный email водителя.');

        $setType = (string) ($vehicle['set_type'] ?? '');
        $rules = vehicleSetTypeRules();
        $rule = $rules[$setType] ?? null;
        if (!$rule) throw new RuntimeException('Выберите тип комплекта ТС.');
        $units = (array) ($vehicle['units'] ?? []);
        foreach ($rule['units'] as $unitRole => $unitRule) {
            $unit = (array) ($units[$unitRole] ?? []);
            if (trim((string) ($unit['plate_number'] ?? '')) === '') throw new RuntimeException('Укажите госномер транспортной единицы.');
            $vin = strtoupper(trim((string) ($unit['vin'] ?? '')));
            if ($vin !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) throw new RuntimeException('Формат VIN: 17 символов.');
            $date = (string) ($unit['diagnostic_card_date'] ?? '');
            if ($date !== '') self::date($date);
            foreach (['capacity_tons', 'volume_m3'] as $measure) {
                $value = trim((string) ($unit[$measure] ?? ''));
                if ($value !== '' && (!is_numeric(str_replace(',', '.', $value)) || (float) str_replace(',', '.', $value) <= 0)) throw new RuntimeException('Грузоподъёмность и объём должны быть положительными числами.');
            }
        }
        self::validateFiles($files);

        // A schema change during the transaction would make rollback ineffective in MySQL.
        foreach (['contractors','contractor_contacts','drivers','driver_phones','vehicle_units','vehicle_sets','driver_vehicle_blocks','crews','crew_drivers','documents','document_types','entity_assignments'] as $table) {
            try { $pdo->query("SELECT 1 FROM `$table` LIMIT 0"); }
            catch (Throwable $missing) { throw new RuntimeException('Необходимые таблицы компании ещё не обновлены: ' . $table); }
        }

        $savedPaths = [];
        $pdo->beginTransaction();
        try {
            $contractorId = $contractorService->createContractor($pdo, $contractor, $userId, $role);
            $contacts = ContractorContactService::normalizeSubmittedContacts($contractor['contacts'] ?? []);
            if ($contacts['errors']) throw new RuntimeException('Проверьте контакты подрядчика.');
            ContractorContactService::replaceForContractor($pdo, $contractorId, $contacts['contacts'], $userId, $role);

            $driverId = $driverService->createDriver($pdo, $driver, $userId, $role);
            if ($extraPhones) {
                $phoneInsert = $pdo->prepare('INSERT INTO driver_phones (driver_id,phone,is_main,comment,created_by_user_id,created_by_role) VALUES (?, ?, 0, ?, ?, ?)');
                foreach ($extraPhones as $index => $extra) $phoneInsert->execute([$driverId, self::phone((string) $extra), trim((string) ($driver['extra_phone_comments'][$index] ?? '')) ?: null, $userId, $role]);
            }

            $membershipId = RegulatedDataScopeService::assertCanCreate('vehicle_unit');
            $unitIds = [];
            $unitInsert = $pdo->prepare('INSERT INTO vehicle_units (plate_number,brand,model,unit_type,vin,capacity_tons,volume_m3,diagnostic_card_number,diagnostic_card_date,status,comments,created_by_user_id,created_by_role) VALUES (?,?,?,?,?,?,?,?,?,\'active\',NULL,?,?)');
            foreach ($rule['units'] as $unitRole => $unitRule) {
                $unit = (array) $units[$unitRole];
                $unitInsert->execute([
                    trim((string) ($unit['plate_number'] ?? '')),
                    trim((string) ($unit['brand'] ?? '')),
                    trim((string) ($unit['model'] ?? '')),
                    (string) $unitRule['unit_type'],
                    strtoupper(trim((string) ($unit['vin'] ?? ''))) ?: null,
                    !empty($unitRule['show_capacity']) && ($unit['capacity_tons'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $unit['capacity_tons']) : null,
                    !empty($unitRule['show_volume']) && ($unit['volume_m3'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $unit['volume_m3']) : null,
                    trim((string) ($unit['diagnostic_card_number'] ?? '')) ?: null,
                    self::date((string) ($unit['diagnostic_card_date'] ?? '')),
                    $userId, $role,
                ]);
                $unitIds[$unitRole] = (int) $pdo->lastInsertId();
                EntityAssignmentService::autoAssignCreator($pdo, 'vehicle_unit', $unitIds[$unitRole], $membershipId);
            }
            $setInsert = $pdo->prepare('INSERT INTO vehicle_sets (set_type,primary_vehicle_unit_id,secondary_vehicle_unit_id,status,comments,created_by_user_id,created_by_membership_id,created_by_role) VALUES (?,?,?,\'active\',?,?,?,?)');
            $setInsert->execute([$setType, $unitIds['primary'], $unitIds['secondary'] ?? null, trim((string) ($vehicle['comments'] ?? '')) ?: null, $userId, $membershipId, $role]);
            $vehicleSetId = (int) $pdo->lastInsertId();

            $blockInsert = $pdo->prepare('INSERT INTO driver_vehicle_blocks (driver_id,vehicle_set_id,status,created_by_user_id,created_by_role) VALUES (?, ?, \'active\', ?, ?)');
            $blockInsert->execute([$driverId, $vehicleSetId, $userId, $role]);
            $blockId = (int) $pdo->lastInsertId();
            $crewInsert = $pdo->prepare('INSERT INTO crews (contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,comments,created_by_user_id,created_by_role) VALUES (?,?,?,?,\'active\',NULL,?,?)');
            $crewInsert->execute([$contractorId, $unitIds['primary'], $driverId, $blockId, $userId, $role]);
            $crewId = (int) $pdo->lastInsertId();
            syncCrewDrivers($pdo, $crewId, [$driverId]);

            self::storeDocuments($pdo, $companyId, 'contractor', $contractorId, $contractor, $files, 'contractor', $userId, $role, $savedPaths);
            self::storeDocuments($pdo, $companyId, 'driver', $driverId, $driver, $files, 'driver', $userId, $role, $savedPaths);
            foreach ($unitIds as $unitRole => $unitId) self::storeDocuments($pdo, $companyId, 'vehicle_unit', $unitId, $vehicle, $files, 'vehicle', $userId, $role, $savedPaths, $unitRole);
            $pdo->commit();
            return $crewId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($savedPaths as $path) if (is_file($path)) @unlink($path);
            throw $error;
        }
    }

    private static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) $digits = '7' . $digits;
        if (strlen($digits) === 11 && $digits[0] === '8') $digits[0] = '7';
        if (strlen($digits) !== 11 || $digits[0] !== '7') return null;
        return sprintf('+7 %s %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
    }

    private static function date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return $raw;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) return "$m[3]-$m[2]-$m[1]";
        throw new RuntimeException('Укажите дату в формате ДД.ММ.ГГГГ.');
    }

    private static function leafFiles(array $group): array
    {
        $walk = static function ($names, array $path) use (&$walk, $group): array {
            if (is_array($names)) {
                $rows = [];
                foreach ($names as $key => $value) $rows = array_merge($rows, $walk($value, [...$path, $key]));
                return $rows;
            }
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
                $value = $group[$field] ?? null;
                foreach ($path as $key) $value = is_array($value) ? ($value[$key] ?? null) : null;
                $file[$field] = $value;
            }
            $file['path'] = $path;
            return [$file];
        };
        return $walk($group['name'] ?? [], []);
    }

    private static function validateFiles(array $files): void
    {
        foreach ($files as $group) {
            if (!is_array($group) || !isset($group['name'])) continue;
            foreach (self::leafFiles($group) as $file) {
                if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) continue;
                if ((int) $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Не удалось загрузить один из документов.');
                $name = (string) $file['name'];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (strpbrk($name, '/\\') !== false || str_contains($name, '..') || !in_array($ext, ['pdf','jpg','jpeg','png','webp','doc','docx','rtf','odt','xls','xlsx','csv','ods','gif','bmp','tif','tiff','heic','heif','txt'], true)) throw new RuntimeException('Недопустимое имя или формат документа.');
                if ((int) $file['size'] > 20 * 1024 * 1024) throw new RuntimeException('Документ превышает 20 МБ.');
            }
        }
    }

    private static function storeDocuments(PDO $pdo, int $companyId, string $entityType, int $entityId, array $post, array $files, string $prefix, int $userId, string $role, array &$savedPaths, ?string $unitRole = null): void
    {
        foreach (['predef_doc' => 'predefined', 'custom_doc_file' => 'custom'] as $kind => $category) {
            foreach (self::leafFiles((array) ($files[$prefix . '_' . $kind] ?? [])) as $file) {
                if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) continue;
                $path = $file['path'];
                if ($unitRole !== null) {
                    if (($path[0] ?? '') !== $unitRole) continue;
                    array_shift($path);
                }
                $code = (string) ($path[0] ?? '');
                if ($category === 'predefined') {
                    $name = (string) ($post['predef_doc_type'][$code] ?? $code);
                    if ($entityType === 'vehicle_unit') {
                        foreach (vehicleSetPredefinedDocumentSections()[$unitRole] ?? [] as $meta) if (($meta['doc_code'] ?? '') === $code) $name = (string) $meta['name'];
                    }
                } else {
                    $index = $path[0] ?? 0;
                    $selected = $unitRole === null ? ($post['custom_doc_type'][$index] ?? '') : ($post['custom_doc_type'][$unitRole][$index] ?? '');
                    $new = $unitRole === null ? ($post['custom_doc_type_new'][$index] ?? '') : ($post['custom_doc_type_new'][$unitRole][$index] ?? '');
                    $name = trim((string) ($new ?: $selected));
                    if ($name === '') throw new RuntimeException('Укажите название дополнительного документа.');
                }
                $typeId = ensureDocumentTypeRecord($pdo, $name, $category === 'predefined' ? $code : '', $entityType, $category);
                $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
                $relativeDir = "companies/$companyId/documents/$entityType/$entityId";
                $absoluteDir = storage_path($relativeDir);
                if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) throw new RuntimeException('Не удалось создать папку документа.');
                $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
                if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) throw new RuntimeException('Не удалось сохранить документ.');
                $savedPaths[] = $absolutePath;
                $insert = $pdo->prepare('INSERT INTO documents (entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role) VALUES (?,?,?,?,?,?,?,?,?,\'uploaded\',?,?,?,?)');
                $insert->execute([$entityType,$entityId,$name,$typeId,$file['name'],$storedName,$relativeDir . '/' . $storedName,$file['type'],$file['size'],$userId,$role,$userId,$role]);
            }
        }
    }
}
