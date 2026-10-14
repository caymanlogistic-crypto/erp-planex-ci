<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use PDO;

/** Read-only catalogue data used when a review card reuses ordinary entities. */
final class RouteExecutorExistingEntityService
{
    private const FORMS = [
        'contractor' => 'lab-contractor-form',
        'driver' => 'lab-driver-form',
        'vehicle' => 'lab-vehicle-form',
    ];

    public function __construct(private readonly PDO $tenant, private readonly int $companyId) {}

    public function search(string $kind, string $query): array
    {
        self::form($kind);
        $query = trim($query);
        if (mb_strlen($query) > 100) throw new DomainException('Слишком длинный запрос поиска.');
        $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        if ($kind === 'contractor') {
            $scope = RegulatedDataScopeService::condition('contractor', 'c.id');
            $sql = "SELECT c.id,c.name,c.inn FROM contractors c WHERE c.deleted_at IS NULL AND c.status='active' AND {$scope['sql']} AND (c.name LIKE ? OR c.inn LIKE ?) ORDER BY c.name LIMIT 30";
            $params = [...$scope['params'], $term, $term];
        } elseif ($kind === 'driver') {
            $scope = RegulatedDataScopeService::condition('driver', 'd.id');
            $sql = "SELECT d.id,d.full_name,d.phone FROM drivers d WHERE d.deleted_at IS NULL AND d.status='active' AND {$scope['sql']} AND (d.full_name LIKE ? OR d.phone LIKE ?) ORDER BY d.full_name LIMIT 30";
            $params = [...$scope['params'], $term, $term];
        } else {
            $scope = RegulatedDataScopeService::condition('vehicle_unit', 'u.id');
            $sql = "SELECT vs.id,vs.set_type,u.plate_number primary_plate,s.plate_number secondary_plate FROM vehicle_sets vs JOIN vehicle_units u ON u.id=vs.primary_vehicle_unit_id LEFT JOIN vehicle_units s ON s.id=vs.secondary_vehicle_unit_id WHERE vs.deleted_at IS NULL AND vs.status='active' AND u.deleted_at IS NULL AND u.status='active' AND (s.id IS NULL OR (s.deleted_at IS NULL AND s.status='active')) AND {$scope['sql']} AND (u.plate_number LIKE ? OR s.plate_number LIKE ?) ORDER BY vs.id DESC LIMIT 30";
            $params = [...$scope['params'], $term, $term];
        }
        $stmt = $this->tenant->prepare($sql);
        $stmt->execute($params);
        return array_map(static function (array $row) use ($kind): array {
            $label = match ($kind) {
                'contractor' => $row['name'] . ' · ИНН ' . $row['inn'],
                'driver' => $row['full_name'] . ' · ' . $row['phone'],
                default => (['single' => 'Одиночка', 'coupling' => 'Сцепка', 'road_train' => 'Автопоезд'][$row['set_type']] ?? 'Комплект ТС') . ' · ' . $row['primary_plate'] . (!empty($row['secondary_plate']) ? ' + ' . $row['secondary_plate'] : ''),
            };
            return ['id' => (int)$row['id'], 'label' => $label];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function detail(string $kind, int $id): array
    {
        $form = self::form($kind);
        if ($id <= 0) throw new DomainException('Запись не найдена.');
        if ($kind === 'contractor') {
            $scope = RegulatedDataScopeService::condition('contractor', 'c.id');
            $stmt = $this->tenant->prepare("SELECT c.* FROM contractors c WHERE c.id=? AND c.deleted_at IS NULL AND c.status='active' AND {$scope['sql']} LIMIT 1");
            $stmt->execute([$id, ...$scope['params']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new DomainException('Подрядчик недоступен.');
            $values = self::pick($row, ['name','inn','contractor_type','kpp','ogrn','director_full_name','director_position','legal_address','physical_address','bank_account','bank_bik','bank_name','bank_corr_account','comments']);
            $stmt = $this->tenant->prepare('SELECT contact_person,phone,email,comment,is_primary,is_document_email FROM contractor_contacts WHERE contractor_id=? ORDER BY is_primary DESC,id');
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index => $contact) {
                foreach (['contact_person','phone','email','comment','is_primary','is_document_email'] as $field) $values["contacts[$index][$field]"] = (string)($contact[$field] ?? '');
            }
            $entities = [['type' => 'contractor', 'id' => $id, 'role' => null]];
            $label = (string)$row['name'];
        } elseif ($kind === 'driver') {
            $scope = RegulatedDataScopeService::condition('driver', 'd.id');
            $stmt = $this->tenant->prepare("SELECT d.* FROM drivers d WHERE d.id=? AND d.deleted_at IS NULL AND d.status='active' AND {$scope['sql']} LIMIT 1");
            $stmt->execute([$id, ...$scope['params']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new DomainException('Водитель недоступен.');
            $values = self::pick($row, ['full_name','phone','passport_number','passport_department_code','passport_issued_by','passport_issue_date','license_number','license_issue_date','snils','email','comments']);
            foreach (['passport_issue_date','license_issue_date'] as $field) $values[$field] = self::date($values[$field]);
            $stmt = $this->tenant->prepare('SELECT phone,comment FROM driver_phones WHERE driver_id=? AND is_main=0 ORDER BY id');
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index => $phone) {
                $values['extra_phones[]'][] = (string)$phone['phone'];
                $values['extra_phone_comments[]'][] = (string)($phone['comment'] ?? '');
            }
            $entities = [['type' => 'driver', 'id' => $id, 'role' => null]];
            $label = (string)$row['full_name'];
        } else {
            $scope = RegulatedDataScopeService::condition('vehicle_unit', 'u.id');
            $stmt = $this->tenant->prepare("SELECT vs.*,u.id primary_id,u.deleted_at primary_deleted,u.status primary_status,s.id secondary_id,s.deleted_at secondary_deleted,s.status secondary_status FROM vehicle_sets vs JOIN vehicle_units u ON u.id=vs.primary_vehicle_unit_id LEFT JOIN vehicle_units s ON s.id=vs.secondary_vehicle_unit_id WHERE vs.id=? AND vs.deleted_at IS NULL AND vs.status='active' AND u.deleted_at IS NULL AND u.status='active' AND (s.id IS NULL OR (s.deleted_at IS NULL AND s.status='active')) AND {$scope['sql']} LIMIT 1");
            $stmt->execute([$id, ...$scope['params']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new DomainException('Комплект ТС недоступен.');
            if (!empty($row['secondary_id'])) {
                $secondaryScope = RegulatedDataScopeService::condition('vehicle_unit', 'u.id');
                $check = $this->tenant->prepare("SELECT 1 FROM vehicle_units u WHERE u.id=? AND u.deleted_at IS NULL AND {$secondaryScope['sql']} LIMIT 1");
                $check->execute([(int)$row['secondary_id'], ...$secondaryScope['params']]);
                if (!$check->fetchColumn()) throw new DomainException('Дополнительное ТС недоступно.');
            }
            $values = ['set_type' => (string)$row['set_type'], 'comments' => (string)($row['comments'] ?? '')];
            $entities = [];
            foreach (['primary' => (int)$row['primary_id'], 'secondary' => (int)($row['secondary_id'] ?? 0)] as $role => $unitId) {
                if (!$unitId) continue;
                $stmt = $this->tenant->prepare('SELECT * FROM vehicle_units WHERE id=? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$unitId]);
                $unit = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$unit) throw new DomainException('Транспортная единица недоступна.');
                foreach (['unit_type','brand','model','plate_number','vin','vin_absent','diagnostic_card_number','diagnostic_card_date','capacity_tons','volume_m3'] as $field) {
                    $value = (string)($unit[$field] ?? '');
                    $values["units[$role][$field]"] = $field === 'diagnostic_card_date' ? self::date($value) : $value;
                }
                $entities[] = ['type' => 'vehicle_unit', 'id' => $unitId, 'role' => $role];
            }
            $label = (string)($values['units[primary][plate_number]'] ?? 'Комплект ТС');
        }
        return ['id' => $id, 'kind' => $kind, 'formId' => $form, 'label' => $label, 'values' => $values, 'files' => $this->documents($kind, $entities)];
    }

    public function canonicalize(array $snapshot): array
    {
        $selected = $snapshot['selected_entities'] ?? [];
        if (!is_array($selected)) throw new DomainException('Некорректный выбор из справочника.');
        $clean = [];
        foreach (self::FORMS as $kind => $form) {
            $id = $selected[$kind] ?? null;
            if ($id === null || $id === '') continue;
            if (!is_int($id) && !ctype_digit((string)$id)) throw new DomainException('Некорректный выбор из справочника.');
            $detail = $this->detail($kind, (int)$id);
            $clean[$kind] = (int)$id;
            $controls = [];
            $labels = [];
            foreach ((array)($snapshot['controls'] ?? []) as $control) {
                if (($control['formId'] ?? '') === $form) $labels[(string)($control['name'] ?? '')][(int)($control['index'] ?? 0)] = (string)($control['label'] ?? '');
                if (($control['formId'] ?? '') !== $form || preg_match('/^(?:predef_doc_type|custom_doc_type|custom_doc_type_new|custom_doc_comment)(?:\[|$)/', (string)($control['name'] ?? ''))) $controls[] = $control;
            }
            foreach ($detail['values'] as $name => $value) {
                foreach ((array)$value as $index => $single) {
                    $checkbox = preg_match('/\[(is_primary|is_document_email)\]$/', (string)$name) === 1;
                    $controls[] = ['formId' => $form, 'name' => $name, 'index' => $index,
                        'value' => (string)$single, 'displayValue' => (string)$single, 'checked' => $checkbox && (bool)$single,
                        'type' => $checkbox ? 'checkbox' : 'text', 'label' => ($labels[$name][$index] ?? '') ?: $name];
                }
            }
            $snapshot['controls'] = $controls;
        }
        $snapshot['selected_entities'] = $clean;
        $removed = $snapshot['removed_documents'] ?? [];
        if (!is_array($removed) || count($removed) > 50) throw new DomainException('Некорректные изменения документов.');
        $allowed = [];
        foreach (array_keys($clean) as $kind) foreach ($this->detail($kind, $clean[$kind])['files'] as $file) $allowed[(int)$file['id']] = true;
        $cleanRemoved = [];
        foreach ($removed as $id) {
            if ((!is_int($id) && !ctype_digit((string)$id)) || !isset($allowed[(int)$id])) throw new DomainException('Документ недоступен.');
            $cleanRemoved[] = (int)$id;
        }
        $snapshot['removed_documents'] = array_values(array_unique($cleanRemoved));
        return $snapshot;
    }

    public function documentsForSnapshot(array $snapshot, array $uploaded): array
    {
        $removed = array_flip(array_map('intval', (array)($snapshot['removed_documents'] ?? [])));
        $newKeys = array_fill_keys(array_column($uploaded, 'key'), true);
        $result = [];
        foreach ((array)($snapshot['selected_entities'] ?? []) as $kind => $id) {
            $detail = $this->detail((string)$kind, (int)$id);
            foreach ($detail['files'] as $file) {
                if (isset($removed[(int)$file['id']]) || isset($newKeys[$file['formId'].'|'.$file['field']])) continue;
                $path = realpath(storage_path((string)$file['relative_path']));
                $root = realpath(storage_path('companies/'.$this->companyId.'/documents'));
                if (!$path || !$root || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/').'/') || !is_file($path)) throw new DomainException('Файл из справочника недоступен.');
                $result[] = ['key' => $file['formId'].'|'.$file['field'], 'index' => (int)$file['index'],
                    'name' => $file['original_name'], 'mime' => $file['mime_type'], 'tmp_name' => $path,
                    'error' => UPLOAD_ERR_OK, 'size' => (int)$file['file_size'], 'existing_document_id' => (int)$file['id']];
            }
        }
        return $result;
    }

    private function documents(string $kind, array $entities): array
    {
        $files = [];
        $custom = 0;
        foreach ($entities as $entity) {
            $stmt = $this->tenant->prepare('SELECT d.id,d.relative_path,d.original_name,d.mime_type,d.file_size,d.document_type,COALESCE(dt.code,\'\') code FROM documents d LEFT JOIN document_types dt ON dt.id=d.document_type_id WHERE d.entity_type=? AND d.entity_id=? AND d.deleted_at IS NULL ORDER BY d.id');
            $stmt->execute([$entity['type'], $entity['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $code = (string)$doc['code'];
                if ($code === '') {
                    $title = mb_strtolower(trim((string)$doc['document_type']));
                    $code = match (true) {
                        str_contains($title, 'водительск') => 'driver_license',
                        str_contains($title, 'паспорт') => 'passport',
                        str_contains($title, 'снилс') => 'snils',
                        str_contains($title, 'стс') => 'sts',
                        str_contains($title, 'диагностическ') => 'diagnostic_card',
                        str_contains($title, 'фото') => 'photo',
                        str_contains($title, 'карточка предприятия') => 'company_card',
                        str_contains($title, 'инн') => 'inn_cert',
                        str_contains($title, 'огрн') => 'ogrn_cert',
                        str_contains($title, 'договор') => 'contract',
                        default => '',
                    };
                }
                $role = $entity['role'];
                $known = match ($kind) {
                    'contractor' => in_array($code, ['company_card','inn_cert','ogrn_cert','contract'], true),
                    'driver' => in_array($code, ['passport','driver_license','snils'], true),
                    default => in_array($code, ['sts','diagnostic_card','photo'], true),
                };
                $field = $known ? ($kind === 'vehicle' ? "predef_doc[$role][$code][]" : ($kind === 'driver' ? "predef_doc[$code][]" : "predef_doc[$code]")) : "custom_doc_file[$custom]";
                $doc['formId'] = self::FORMS[$kind];
                $doc['field'] = $field;
                $doc['index'] = 0;
                $doc['title'] = (string)($doc['document_type'] ?: $code ?: $doc['original_name']);
                $files[] = $doc;
                if (!$known) $custom++;
            }
        }
        return $files;
    }

    private static function form(string $kind): string
    { return self::FORMS[$kind] ?? throw new DomainException('Неизвестный справочник.'); }

    private static function pick(array $row, array $names): array
    {
        $out = [];
        foreach ($names as $name) $out[$name] = (string)($row[$name] ?? '');
        return $out;
    }

    private static function date(string $date): string
    { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) ? "$m[3].$m[2].$m[1]" : $date; }
}
