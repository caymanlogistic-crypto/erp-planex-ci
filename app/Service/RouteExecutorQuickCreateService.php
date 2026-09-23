<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class RouteExecutorQuickCreateService
{
    public const MODE_NEW_CONTRACTOR = 'new_contractor';
    public const MODE_EXISTING_CONTRACTOR = 'existing_contractor';

    /** @var array<string,array<string,string>> */
    private const DOCUMENTS = [
        'contractor' => [
            'company_card' => 'Карточка предприятия',
            'inn_cert' => 'Свидетельство ИНН',
            'ogrn_cert' => 'Свидетельство ОГРН',
            'contract' => 'Договор',
        ],
        'driver' => [
            'passport' => 'Паспорт',
            'driver_license' => 'Водительское удостоверение',
            'snils' => 'СНИЛС',
        ],
        'primary' => [
            'sts' => 'СТС',
            'diagnostic_card' => 'Диагностическая карта',
            'photo' => 'Фотография',
        ],
        'secondary' => [
            'sts' => 'СТС',
            'diagnostic_card' => 'Диагностическая карта',
            'photo' => 'Фотография',
        ],
    ];

    public static function normalizeMode(string $mode): string
    {
        return in_array($mode, [self::MODE_NEW_CONTRACTOR, self::MODE_EXISTING_CONTRACTOR], true)
            ? $mode
            : self::MODE_NEW_CONTRACTOR;
    }

    /** @return array<string,string> */
    public static function validate(PDO $pdo, array &$input, string $mode, ContractorService $contractorService): array
    {
        $mode = self::normalizeMode($mode);
        $errors = [];

        if ($mode === self::MODE_NEW_CONTRACTOR) {
            $contractor = is_array($input['contractor'] ?? null) ? $input['contractor'] : [];
            $contractor['name'] = self::compactSpaces((string)($contractor['name'] ?? ''));
            $contractor['inn'] = preg_replace('/\D+/', '', (string)($contractor['inn'] ?? '')) ?? '';
            $contractor['kpp'] = preg_replace('/\D+/', '', (string)($contractor['kpp'] ?? '')) ?? '';
            $contractor['ogrn'] = preg_replace('/\D+/', '', (string)($contractor['ogrn'] ?? '')) ?? '';
            $contractor['contact_phone'] = self::normalizePhone((string)($contractor['contact_phone'] ?? '')) ?? trim((string)($contractor['contact_phone'] ?? ''));
            $contractor['contact_email'] = mb_strtolower(trim((string)($contractor['contact_email'] ?? '')), 'UTF-8');
            $input['contractor'] = $contractor;

            foreach ($contractorService->validateContractor($contractor, $pdo) as $field => $message) {
                $errors['contractor.' . $field] = (string)$message;
            }
            if ($contractor['contact_email'] !== '' && !filter_var($contractor['contact_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['contractor.contact_email'] = 'Укажите корректный email.';
            }
            if (trim((string)($contractor['contact_phone'] ?? '')) !== '' && self::normalizePhone((string)$contractor['contact_phone']) === null) {
                $errors['contractor.contact_phone'] = 'Телефон должен содержать 10 или 11 цифр.';
            }
        } else {
            $contractorId = (int)($input['contractor_id'] ?? 0);
            if ($contractorId <= 0) {
                $errors['contractor_id'] = 'Выберите подрядчика.';
            } else {
                $stmt = $pdo->prepare("SELECT 1 FROM contractors WHERE id=? AND deleted_at IS NULL AND status='active'");
                $stmt->execute([$contractorId]);
                if (!$stmt->fetchColumn() || !RouteExecutorService::canAccessContractor($pdo, $contractorId)) {
                    $errors['contractor_id'] = 'Подрядчик не найден, неактивен или недоступен.';
                }
            }
        }

        $driver = is_array($input['driver'] ?? null) ? $input['driver'] : [];
        $rawPassportNumber = trim((string)($driver['passport_number'] ?? ''));
        $rawLicenseNumber = trim((string)($driver['license_number'] ?? ''));
        $driver['full_name'] = self::normalizeFullName((string)($driver['full_name'] ?? ''));
        $rawDriverPhone = trim((string)($driver['phone'] ?? ''));
        $driver['phone'] = self::normalizePhone($rawDriverPhone) ?? $rawDriverPhone;
        $driver['email'] = mb_strtolower(trim((string)($driver['email'] ?? '')), 'UTF-8');
        $driver['passport_number'] = self::normalizeTenDigits($rawPassportNumber);
        $driver['license_number'] = self::normalizeTenDigits($rawLicenseNumber);
        $input['driver'] = $driver;

        if (count(preg_split('/\s+/u', trim($driver['full_name'])) ?: []) !== 3) {
            $errors['driver.full_name'] = 'Укажите фамилию, имя и отчество.';
        }
        if ($rawDriverPhone === '' || self::normalizePhone($rawDriverPhone) === null) {
            $errors['driver.phone'] = 'Укажите телефон из 10 или 11 цифр.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM drivers WHERE phone=? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$driver['phone']]);
            if ($stmt->fetchColumn()) {
                $errors['driver.phone'] = 'Водитель с таким телефоном уже существует.';
            }
        }
        if ($driver['email'] !== '' && !filter_var($driver['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['driver.email'] = 'Укажите корректный email.';
        }
        if ($rawPassportNumber !== '' && $driver['passport_number'] === null) {
            $errors['driver.passport_number'] = 'Паспорт должен содержать 10 цифр.';
            $input['driver']['passport_number'] = $rawPassportNumber;
        }
        if ($rawLicenseNumber !== '' && $driver['license_number'] === null) {
            $errors['driver.license_number'] = 'Номер ВУ должен содержать 10 цифр.';
            $input['driver']['license_number'] = $rawLicenseNumber;
        }

        $vehicle = is_array($input['vehicle'] ?? null) ? $input['vehicle'] : [];
        $setType = trim((string)($vehicle['set_type'] ?? 'single'));
        $rules = vehicleSetTypeRules();
        if (!isset($rules[$setType])) {
            $errors['vehicle.set_type'] = 'Выберите тип транспорта.';
            $setType = 'single';
        }
        $vehicle['set_type'] = $setType;
        $vehicle['primary'] = self::normalizeUnit(is_array($vehicle['primary'] ?? null) ? $vehicle['primary'] : []);
        $vehicle['secondary'] = self::normalizeUnit(is_array($vehicle['secondary'] ?? null) ? $vehicle['secondary'] : []);
        $input['vehicle'] = $vehicle;

        self::validateUnit($pdo, $vehicle['primary'], 'vehicle.primary', true, $errors);
        $secondaryRequired = !empty($rules[$setType]['secondary_required']);
        if ($secondaryRequired) {
            self::validateUnit($pdo, $vehicle['secondary'], 'vehicle.secondary', true, $errors);
            if ($vehicle['secondary']['plate_number'] !== '' && $vehicle['secondary']['plate_number'] === $vehicle['primary']['plate_number']) {
                $errors['vehicle.secondary.plate_number'] = 'Госномера частей комплекта должны отличаться.';
            }
        }

        return $errors;
    }

    /**
     * @return array{crew_id:int,contractor_id:int,driver_id:int,vehicle_set_id:int,uploaded_documents:int}
     */
    public static function create(
        PDO $pdo,
        int $companyId,
        array $input,
        array $files,
        string $mode,
        int $userId,
        string $role,
        ContractorService $contractorService,
        DriverService $driverService
    ): array {
        $mode = self::normalizeMode($mode);
        $errors = self::validate($pdo, $input, $mode, $contractorService);
        $uploadPlan = self::buildUploadPlan($files, (string)($input['vehicle']['set_type'] ?? 'single'), $mode);
        foreach ($uploadPlan['errors'] as $key => $message) {
            $errors[$key] = $message;
        }
        if ($errors) {
            throw new RouteExecutorQuickCreateValidationException($errors, $input);
        }

        $storedPaths = [];
        $pdo->beginTransaction();
        try {
            $membershipId = AccessControlService::effectiveMembershipId();
            if ($mode === self::MODE_NEW_CONTRACTOR) {
                $contractorData = $input['contractor'];
                $contractorId = $contractorService->createContractor($pdo, $contractorData, $userId, $role);
                $contacts = [];
                if (($contractorData['contact_phone'] ?? '') !== '' || ($contractorData['contact_email'] ?? '') !== '') {
                    $contacts[] = [
                        'contact_person' => null,
                        'phone' => (string)($contractorData['contact_phone'] ?? ''),
                        'email' => (string)($contractorData['contact_email'] ?? ''),
                        'comment' => null,
                        'is_primary' => 1,
                        'is_document_email' => ($contractorData['contact_email'] ?? '') !== '' ? 1 : 0,
                    ];
                }
                ContractorContactService::replaceForContractor($pdo, $contractorId, $contacts, $userId, $role);
            } else {
                $contractorId = (int)$input['contractor_id'];
                $stmt = $pdo->prepare("SELECT 1 FROM contractors WHERE id=? AND deleted_at IS NULL AND status='active' FOR UPDATE");
                $stmt->execute([$contractorId]);
                if (!$stmt->fetchColumn() || !RouteExecutorService::canAccessContractor($pdo, $contractorId)) {
                    throw new RuntimeException('Выбранный подрядчик больше недоступен.');
                }
            }

            $driverData = $input['driver'] + [
                'license_category' => null,
                'license_issue_date' => null,
                'license_expire_date' => null,
                'passport_issued_by' => null,
                'passport_department_code' => null,
                'passport_issue_date' => null,
                'snils' => null,
                'comments' => null,
            ];
            $driverId = $driverService->createDriver($pdo, $driverData, $userId, $role);

            $vehicleMembershipId = RegulatedDataScopeService::assertCanCreate('vehicle_unit');
            $rules = vehicleSetTypeRules();
            $setType = (string)$input['vehicle']['set_type'];
            $unitIds = [];
            foreach (array_keys($rules[$setType]['units']) as $unitRole) {
                $unit = $input['vehicle'][$unitRole];
                $unitRule = $rules[$setType]['units'][$unitRole];
                $insertUnit = $pdo->prepare(
                    'INSERT INTO vehicle_units
                        (plate_number,brand,model,unit_type,vin,capacity_tons,volume_m3,status,created_by_user_id,created_by_membership_id,created_by_role)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $insertUnit->execute([
                    $unit['plate_number'],
                    $unit['brand'] !== '' ? $unit['brand'] : null,
                    $unit['model'] !== '' ? $unit['model'] : null,
                    $unitRule['unit_type'],
                    $unit['vin'] !== '' ? $unit['vin'] : null,
                    $unit['capacity_tons'] !== '' ? (float)$unit['capacity_tons'] : null,
                    $unit['volume_m3'] !== '' ? (float)$unit['volume_m3'] : null,
                    'active',
                    $userId,
                    $vehicleMembershipId > 0 ? $vehicleMembershipId : null,
                    $role,
                ]);
                $unitIds[$unitRole] = (int)$pdo->lastInsertId();
                EntityAssignmentService::autoAssignCreator(
                    $pdo,
                    'vehicle_unit',
                    $unitIds[$unitRole],
                    $vehicleMembershipId > 0 ? $vehicleMembershipId : null
                );
            }

            $insertSet = $pdo->prepare(
                'INSERT INTO vehicle_sets
                    (set_type,primary_vehicle_unit_id,secondary_vehicle_unit_id,status,created_by_user_id,created_by_membership_id,created_by_role)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $insertSet->execute([
                $setType,
                $unitIds['primary'],
                $unitIds['secondary'] ?? null,
                'active',
                $userId,
                $membershipId,
                $role,
            ]);
            $vehicleSetId = (int)$pdo->lastInsertId();

            $insertBlock = $pdo->prepare(
                "INSERT INTO driver_vehicle_blocks (driver_id,vehicle_set_id,status,created_by_user_id,created_by_role)
                 VALUES (?,?,'active',?,?)"
            );
            $insertBlock->execute([$driverId, $vehicleSetId, $userId, $role]);
            $blockId = (int)$pdo->lastInsertId();

            $insertCrew = $pdo->prepare(
                "INSERT INTO crews
                    (contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,created_by_user_id,created_by_role)
                 VALUES (?,?,?,?,'active',?,?)"
            );
            $insertCrew->execute([$contractorId, $unitIds['primary'], $driverId, $blockId, $userId, $role]);
            $crewId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO crew_drivers (crew_id,driver_id,position) VALUES (?,?,1)')->execute([$crewId, $driverId]);

            $entityIds = [
                'contractor' => $contractorId,
                'driver' => $driverId,
                'primary' => $unitIds['primary'],
                'secondary' => $unitIds['secondary'] ?? 0,
            ];
            foreach ($uploadPlan['files'] as $upload) {
                $entityId = (int)($entityIds[$upload['entity_key']] ?? 0);
                if ($entityId <= 0) {
                    continue;
                }
                self::storeDocument(
                    $pdo,
                    $companyId,
                    $upload['entity_type'],
                    $entityId,
                    $upload['code'],
                    $upload['name'],
                    $upload['file'],
                    $userId,
                    $role,
                    $storedPaths
                );
            }

            $pdo->commit();
            return [
                'crew_id' => $crewId,
                'contractor_id' => $contractorId,
                'driver_id' => $driverId,
                'vehicle_set_id' => $vehicleSetId,
                'uploaded_documents' => count($uploadPlan['files']),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($storedPaths as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }
    }

    private static function compactSpaces(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }

    private static function normalizeFullName(string $value): string
    {
        $parts = preg_split('/\s+/u', self::compactSpaces($value)) ?: [];
        return implode(' ', array_map(static function (string $part): string {
            $part = mb_strtolower($part, 'UTF-8');
            return mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($part, 1, null, 'UTF-8');
        }, $parts));
    }

    private static function normalizePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return null;
        }
        return sprintf('+7 %s %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
    }

    private static function normalizeTenDigits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        return strlen($digits) === 10 ? substr($digits, 0, 4) . ' ' . substr($digits, 4) : null;
    }

    /** @return array<string,string> */
    private static function normalizeUnit(array $unit): array
    {
        return [
            'plate_number' => mb_strtoupper(self::compactSpaces((string)($unit['plate_number'] ?? '')), 'UTF-8'),
            'brand' => self::compactSpaces((string)($unit['brand'] ?? '')),
            'model' => self::compactSpaces((string)($unit['model'] ?? '')),
            'vin' => mb_strtoupper(trim((string)($unit['vin'] ?? '')), 'UTF-8'),
            'capacity_tons' => str_replace(',', '.', trim((string)($unit['capacity_tons'] ?? ''))),
            'volume_m3' => str_replace(',', '.', trim((string)($unit['volume_m3'] ?? ''))),
        ];
    }

    private static function validateUnit(PDO $pdo, array $unit, string $prefix, bool $required, array &$errors): void
    {
        if ($required && $unit['plate_number'] === '') {
            $errors[$prefix . '.plate_number'] = 'Укажите госномер.';
        } elseif ($unit['plate_number'] !== '') {
            $stmt = $pdo->prepare('SELECT 1 FROM vehicle_units WHERE plate_number=? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$unit['plate_number']]);
            if ($stmt->fetchColumn()) {
                $errors[$prefix . '.plate_number'] = 'ТС с таким госномером уже существует.';
            }
        }
        if ($unit['vin'] !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $unit['vin'])) {
            $errors[$prefix . '.vin'] = 'VIN должен содержать 17 допустимых символов.';
        }
        foreach (['capacity_tons' => 'Грузоподъёмность', 'volume_m3' => 'Объём'] as $field => $label) {
            if ($unit[$field] !== '' && (!is_numeric(str_replace(',', '.', $unit[$field])) || (float)str_replace(',', '.', $unit[$field]) < 0)) {
                $errors[$prefix . '.' . $field] = $label . ' должен быть положительным числом.';
            }
        }
    }

    /** @return array{files:list<array<string,mixed>>,errors:array<string,string>} */
    private static function buildUploadPlan(array $files, string $setType, string $mode): array
    {
        $plan = [];
        $errors = [];
        $allowedEntities = ['driver', 'primary'];
        if (isset(vehicleSetTypeRules()[$setType]['units']['secondary'])) {
            $allowedEntities[] = 'secondary';
        }
        if ($mode === self::MODE_NEW_CONTRACTOR) {
            $allowedEntities[] = 'contractor';
        }

        foreach ($allowedEntities as $entityKey) {
            foreach (self::DOCUMENTS[$entityKey] as $code => $name) {
                foreach (self::normalizeFiles($files['quick_docs'] ?? [], $entityKey, $code) as $index => $file) {
                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') {
                        continue;
                    }
                    $validationError = DocumentService::validateUploadedFile($file, true);
                    if ($validationError !== null) {
                        $errors['documents.' . $entityKey . '.' . $code . '.' . $index] = self::uploadErrorMessage($name, $validationError);
                        continue;
                    }
                    $plan[] = [
                        'entity_key' => $entityKey,
                        'entity_type' => in_array($entityKey, ['primary', 'secondary'], true) ? 'vehicle_unit' : $entityKey,
                        'code' => $code,
                        'name' => $name,
                        'file' => $file,
                    ];
                }
            }
        }
        return ['files' => $plan, 'errors' => $errors];
    }

    /** @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}> */
    private static function normalizeFiles(array $files, string $entityKey, string $code): array
    {
        $names = $files['name'][$entityKey][$code] ?? [];
        if (!is_array($names)) {
            $names = [$names];
        }
        $result = [];
        foreach ($names as $index => $name) {
            $result[] = [
                'name' => (string)$name,
                'type' => (string)($files['type'][$entityKey][$code][$index] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$entityKey][$code][$index] ?? ''),
                'error' => (int)($files['error'][$entityKey][$code][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($files['size'][$entityKey][$code][$index] ?? 0),
            ];
        }
        return $result;
    }

    private static function uploadErrorMessage(string $name, string $code): string
    {
        return match ($code) {
            'file_too_large' => $name . ': файл больше 20 МБ.',
            'invalid_extension', 'invalid_mime' => $name . ': неподдерживаемый формат файла.',
            'invalid_filename' => $name . ': недопустимое имя файла.',
            default => $name . ': файл не удалось проверить.',
        };
    }

    private static function storeDocument(
        PDO $pdo,
        int $companyId,
        string $entityType,
        int $entityId,
        string $code,
        string $name,
        array $file,
        int $userId,
        string $role,
        array &$storedPaths
    ): void {
        $storedName = DocumentService::makeSafeFilename((string)$file['name']);
        $relativeDir = 'companies/' . $companyId . '/documents/' . $entityType . '/' . $entityId;
        $absoluteDir = storage_path($relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Не удалось создать каталог документов.');
        }
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Не удалось сохранить документ «' . $name . '».');
        }
        $storedPaths[] = $absolutePath;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: 'application/octet-stream';
        $documentTypeId = ensureDocumentTypeRecord($pdo, $name, $code, $entityType, 'predefined');
        $stmt = $pdo->prepare(
            'INSERT INTO documents
                (entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role)
             VALUES (?,?,?,?,?,?,?,?,?,\'uploaded\',?,?,?,?)'
        );
        $stmt->execute([
            $entityType,
            $entityId,
            $name,
            $documentTypeId,
            $file['name'],
            $storedName,
            $relativeDir . '/' . $storedName,
            $mime,
            (int)$file['size'],
            $userId,
            $role,
            $userId,
            $role,
        ]);
    }
}

final class RouteExecutorQuickCreateValidationException extends RuntimeException
{
    public function __construct(
        private readonly array $validationErrors,
        private readonly array $normalizedInput
    ) {
        parent::__construct('Проверьте заполнение формы.');
    }

    public function errors(): array
    {
        return $this->validationErrors;
    }

    public function input(): array
    {
        return $this->normalizedInput;
    }
}
