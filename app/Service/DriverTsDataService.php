<?php
declare(strict_types=1);

namespace App\Service;

use App\Security\AccessRuntime;
use PDO;
use RuntimeException;
use ZipArchive;

/** Read-only data for crews assigned to at least one waste trip. */
final class DriverTsDataService
{
    public function __construct(private readonly PDO $pdo) {}

    public function listing(): array
    {
        if (!self::canReadPage()) return [];
        $rows = $this->pdo->query(
            "SELECT c.id AS crew_id, c.created_at, d.full_name AS driver_name, d.phone AS driver_phone,
                    d.email AS driver_email, vu1.brand AS primary_brand, vu1.plate_number AS primary_plate,
                    vu1.capacity_tons AS primary_capacity, vu1.volume_m3 AS primary_volume,
                    vu2.brand AS secondary_brand, vu2.plate_number AS secondary_plate,
                    vu2.capacity_tons AS secondary_capacity, vu2.volume_m3 AS secondary_volume,
                    er.membership_id AS responsible_membership_id, u.full_name AS created_by_name
               FROM crews c
               JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
               JOIN drivers d ON d.id=dvb.driver_id
               JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
               LEFT JOIN vehicle_units vu1 ON vu1.id=vs.primary_vehicle_unit_id
               LEFT JOIN vehicle_units vu2 ON vu2.id=vs.secondary_vehicle_unit_id
               LEFT JOIN entity_responsibilities er ON er.entity_type='route_executor' AND er.entity_id=c.id
               LEFT JOIN users u ON u.id=c.created_by_user_id
              WHERE c.deleted_at IS NULL
                AND EXISTS (SELECT 1 FROM waste_trips wt WHERE wt.route_executor_id=c.id AND wt.deleted_at IS NULL)
              ORDER BY c.created_at DESC, c.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return [];
        $ids = array_map(static fn(array $row): int => (int)$row['crew_id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT route_executor_id, business_id FROM waste_trips WHERE deleted_at IS NULL AND route_executor_id IN ($marks) ORDER BY business_id");
        $stmt->execute($ids);
        $tripIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $trip) {
            $tripIds[(int)$trip['route_executor_id']][] = (int)$trip['business_id'];
        }
        foreach ($rows as &$row) {
            $row['trips'] = array_values(array_unique($tripIds[(int)$row['crew_id']] ?? []));
            $row['driver_name'] = \crewDriverNames($this->pdo, (int)$row['crew_id'], (string)$row['driver_name']);
        }
        unset($row);
        return $rows;
    }

    public function detail(int $crewId): ?array
    {
        if ($crewId <= 0 || !self::canReadPage()) return null;
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS crew_id, dvb.id AS block_id, d.id AS driver_id,
                    vs.id AS set_id, vs.set_type, vs.comments AS set_comments,
                    vs.primary_vehicle_unit_id AS primary_id, vs.secondary_vehicle_unit_id AS secondary_id
               FROM crews c
               JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
               JOIN drivers d ON d.id=dvb.driver_id
               JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
              WHERE c.id=? AND c.deleted_at IS NULL
                AND EXISTS (SELECT 1 FROM waste_trips wt WHERE wt.route_executor_id=c.id AND wt.deleted_at IS NULL)"
        );
        $stmt->execute([$crewId]);
        $crew = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$crew) return null;
        $tripStmt = $this->pdo->prepare('SELECT business_id FROM waste_trips WHERE route_executor_id=? AND deleted_at IS NULL ORDER BY business_id');
        $tripStmt->execute([$crewId]);
        $crew['trips'] = array_map('intval', $tripStmt->fetchAll(PDO::FETCH_COLUMN));

        $driverIds = \loadCrewDriverIds($this->pdo, $crewId, (int)$crew['driver_id']);
        $marks = implode(',', array_fill(0, count($driverIds), '?'));
        $drivers = $this->pdo->prepare("SELECT * FROM drivers WHERE id IN ($marks) ORDER BY id");
        $drivers->execute($driverIds);
        $crew['drivers'] = $drivers->fetchAll(PDO::FETCH_ASSOC);
        $phones = $this->pdo->prepare("SELECT driver_id, phone, comment FROM driver_phones WHERE driver_id IN ($marks) ORDER BY is_main DESC, id ASC");
        $phones->execute($driverIds);
        $crew['phones'] = [];
        foreach ($phones->fetchAll(PDO::FETCH_ASSOC) as $phone) $crew['phones'][(int)$phone['driver_id']][] = $phone;
        $unitIds = array_values(array_filter([(int)$crew['primary_id'], (int)$crew['secondary_id']]));
        $unitMarks = implode(',', array_fill(0, count($unitIds), '?'));
        $units = $this->pdo->prepare("SELECT * FROM vehicle_units WHERE id IN ($unitMarks)");
        $units->execute($unitIds);
        $crew['units'] = [];
        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $unit) $crew['units'][(int)$unit['id']] = $unit;
        $crew['files'] = $this->documents($crew);
        return $crew;
    }

    private static function canReadPage(): bool
    {
        // This read-only directory has its own grant. Reusing route-executor
        // data scopes would silently empty it for roles granted only this page.
        return AccessRuntime::current()->canAccessPage('company.driver_ts_data');
    }

    private function documents(array $crew): array
    {
        $targets = [];
        foreach ($crew['drivers'] as $driver) $targets[] = ['driver', (int)$driver['id']];
        foreach (['primary_id', 'secondary_id'] as $key) if ((int)$crew[$key] > 0) $targets[] = ['vehicle_unit', (int)$crew[$key]];
        $targets[] = ['vehicle_set', (int)$crew['set_id']];
        $targets[] = ['driver_vehicle_block', (int)$crew['block_id']];
        $targets[] = ['crew', (int)$crew['crew_id']];
        $clauses = [];
        $params = [];
        foreach ($targets as [$type, $id]) { $clauses[] = '(d.entity_type=? AND d.entity_id=?)'; $params[]=$type; $params[]=$id; }
        $stmt = $this->pdo->prepare('SELECT d.id, d.entity_type, d.entity_id, d.document_type, dt.code AS document_code, d.original_name, d.relative_path, d.file_size, d.mime_type FROM documents d LEFT JOIN document_types dt ON dt.id=d.document_type_id WHERE d.deleted_at IS NULL AND ('.implode(' OR ', $clauses).') ORDER BY d.id');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Use the same semantic document names and duplicate suffixes as the emailed ZIP. */
    public static function archiveDocumentNames(array $detail): array
    {
        $primary = $detail['units'][(int)$detail['primary_id']] ?? [];
        $secondary = $detail['units'][(int)$detail['secondary_id']] ?? [];
        $driver = self::primaryDriverName($detail);
        $package = [
            'driver' => $driver,
            'plate' => (string)($primary['plate_number'] ?? ''),
            'secondary_plate' => (string)($secondary['plate_number'] ?? ''),
        ];
        $codes = [
            'Паспорт' => 'passport', 'Водительское удостоверение' => 'driver_license',
            'СНИЛС' => 'snils', 'СТС' => 'sts', 'Свидетельство о регистрации' => 'registration_certificate',
            'Диагностическая карта' => 'diagnostic_card', 'Фотография' => 'photo', 'Фото' => 'photo',
        ];
        $attachments = [];
        foreach ($detail['files'] as $doc) {
            $code = trim((string)($doc['document_code'] ?? ''));
            if ($code === '') $code = $codes[trim((string)($doc['document_type'] ?? ''))] ?? '';
            $role = (string)($doc['entity_type'] ?? '') === 'vehicle_unit' && (int)($doc['entity_id'] ?? 0) === (int)($detail['secondary_id'] ?? 0) ? 'secondary' : 'primary';
            $attachments[] = [
                'key' => $code === '' ? '' : 'predef_doc['.$role.']['.$code.'][]',
                'name' => (string)$doc['original_name'],
            ];
        }
        return RouteExecutorReviewArchive::names($attachments, $package);
    }

    public static function archiveDownloadName(array $detail): string
    {
        $driver = self::primaryDriverName($detail);
        $plate = trim((string)($detail['units'][(int)$detail['primary_id']]['plate_number'] ?? ''));
        $name = trim($driver.' '.$plate);
        $name = trim((string)preg_replace('/[\\\\\/\x00-\x1f:<>"|?*]+/u', ' ', $name));
        return ($name !== '' ? $name : 'Данные водителя и ТС').'.zip';
    }

    private static function primaryDriverName(array $detail): string
    {
        foreach ($detail['drivers'] as $driver) {
            if ((int)($driver['id'] ?? 0) === (int)($detail['driver_id'] ?? 0)) return trim((string)($driver['full_name'] ?? ''));
        }
        return trim((string)($detail['drivers'][0]['full_name'] ?? ''));
    }

    /** Same labels and values feed the popup and the workbook. */
    public static function tableRows(array $detail): array
    {
        $date=static function (mixed $value): string {
            $text=(string)($value ?? '');
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$text,$m) ? "$m[3].$m[2].$m[1]" : $text;
        };
        $driverRows = [];
        foreach ($detail['drivers'] as $index => $driver) {
            if ($index > 0) $driverRows[] = ['Водитель '.($index + 1), ''];
            foreach (['ФИО'=>'full_name','Телефон'=>'phone','Email'=>'email','СНИЛС'=>'snils',
                      'Серия и номер'=>'passport_number','Код подразделения'=>'passport_department_code',
                      'Кем выдан'=>'passport_issued_by','Дата выдачи'=>'passport_issue_date',
                      'Номер ВУ'=>'license_number','Категория ВУ'=>'license_category',
                      'Дата выдачи ВУ'=>'license_issue_date','Срок действия ВУ'=>'license_expire_date',
                      'Комментарий'=>'comments'] as $label=>$key) {
                $value=$driver[$key] ?? '';
                $driverRows[] = [$label, str_contains($key,'date') ? $date($value) : (string)$value];
            }
            foreach (($detail['phones'][(int)$driver['id']] ?? []) as $phone) {
                if ((string)($phone['phone'] ?? '') !== (string)($driver['phone'] ?? '')) {
                    $driverRows[] = ['Доп контакты', (string)$phone['phone']];
                    if (trim((string)($phone['comment'] ?? '')) !== '') $driverRows[] = ['Комментарий к доп контактам', (string)$phone['comment']];
                }
            }
        }
        $setType=(string)$detail['set_type'];
        $vehicleRows = [['Тип комплекта', (string)(\vehicleSetTypeRules()[$setType]['label'] ?? $setType)]];
        foreach (['primary_id'=>'primary', 'secondary_id'=>'secondary'] as $key=>$role) {
            $unit = $detail['units'][(int)$detail[$key]] ?? null;
            if (!$unit) continue;
            $vehicleRows[] = [$role === 'primary' ? ($setType==='single' ? 'Автомобиль' : ($setType==='road_train' ? 'Первая часть' : 'Тягач')) : ($setType==='road_train' ? 'Прицеп' : 'Полуприцеп'), ''];
            foreach (['Марка'=>'brand','Модель'=>'model','Госномер'=>'plate_number','Тип'=>'unit_type',
                      'VIN'=>'vin','СТС'=>'sts_number','ПТС'=>'pts_number',
                      'Диагностическая карта'=>'diagnostic_card_number',
                      'Дата диагностической карты'=>'diagnostic_card_date',
                      'Срок диагностической карты'=>'diagnostic_card_expire',
                      'Грузоподъёмность (т)'=>'capacity_tons',
                      'Объём кузова (м³)'=>'volume_m3','Комментарий'=>'comments'] as $label=>$field) {
                $value = $field === 'vin' && !empty($unit['vin_absent']) ? 'Отсутствует' : (string)($unit[$field] ?? '');
                $vehicleRows[] = [$label, str_contains($field,'date') || str_contains($field,'expire') ? $date($value) : $value];
            }
        }
        if (trim((string)$detail['set_comments']) !== '') $vehicleRows[] = ['Комментарий к комплекту', (string)$detail['set_comments']];
        return ['driver'=>$driverRows, 'vehicle'=>$vehicleRows];
    }

    public static function workbook(array $sections): string
    {
        $path = tempnam(sys_get_temp_dir(), 'planex-xlsx-');
        if ($path === false) throw new RuntimeException('Не удалось создать Excel-файл.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) { @unlink($path); throw new RuntimeException('Не удалось создать Excel-файл.'); }
        $xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = [['ВОДИТЕЛЬ', ''], ...$sections['driver'], ['', ''], ['ТРАНСПОРТНОЕ СРЕДСТВО', ''], ...$sections['vehicle']];
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="32" customWidth="1"/><col min="2" max="2" width="55" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $i=>$pair) {
            $n=$i+1; $sheet.='<row r="'.$n.'"><c r="A'.$n.'" t="inlineStr"><is><t>'.$xml((string)$pair[0]).'</t></is></c><c r="B'.$n.'" t="inlineStr"><is><t>'.$xml((string)$pair[1]).'</t></is></c></row>';
        }
        $sheet.='</sheetData></worksheet>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Данные водителя и ТС" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        return $path;
    }
}
