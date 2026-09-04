from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]

def patch(path, old, new, expected=1):
    p=ROOT/path
    s=p.read_text(encoding='utf-8')
    n=s.count(old)
    if n != expected:
        raise SystemExit(f'{path}: expected {expected} occurrences, got {n}: {old[:120]!r}')
    p.write_text(s.replace(old,new),encoding='utf-8')

patch('app/Service/DocumentTemplateService.php',
"        'vehicle.model', 'vehicle.plate', 'trailer.display',",
"        'vehicle.label', 'vehicle.model', 'vehicle.plate', 'vehicle.vin',\n        'trailer.label', 'trailer.model', 'trailer.plate', 'trailer.vin', 'trailer.display',")
patch('app/Service/DocumentTemplateService.php',
"            $escaped = htmlspecialchars($node['text'], ENT_QUOTES | ENT_XML1, 'UTF-8');",
"            $escaped = htmlspecialchars($node['text'], ENT_QUOTES | ENT_XML1, 'UTF-8');\n            // Replacement values may contain semantic line breaks (for example trailer.display).\n            // WordprocessingML does not reliably render literal LF inside w:t, so emit w:br elements.\n            $escaped = str_replace([\"\\r\\n\", \"\\r\", \"\\n\"], '</w:t><w:br/><w:t>', $escaped);")

patch('app/Service/DocumentGeneratorTemplateTrait.php',
"                'vehicle_lease_crew' => ['Договор-заявка аренды ТС с экипажем', 'contract_vehicle_lease_crew_v2.docx'],",
"                'vehicle_lease_crew' => ['Договор-заявка аренды ТС с экипажем', 'contract_vehicle_lease_crew_v3.docx'],")
patch('app/Service/DocumentGeneratorTemplateTrait.php',
"            'contract_vehicle_lease_crew_v2.docx' => '7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',",
"            'contract_vehicle_lease_crew_v2.docx' => '7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',\n            'contract_vehicle_lease_crew_v3.docx' => '87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',")

patch('app/Service/DocumentGeneratorService.php',
"require_once __DIR__ . '/DocumentNumberSequenceService.php';",
"require_once __DIR__ . '/DocumentNumberSequenceService.php';\nrequire_once __DIR__ . '/GoogleSheetsRequestContactService.php';")

p=ROOT/'app/Service/GoogleSheetsRequestContactService.php'
s=p.read_text(encoding='utf-8')
s=s.replace("    /** @var callable|null */\n    private $fetcher;", "    /** @var callable|null */\n    private static $testDefaultFetcher = null;\n    /** @var callable|null */\n    private $fetcher;")
s=s.replace("        $this->fetcher = $fetcher;", "        $this->fetcher = $fetcher ?? self::$testDefaultFetcher;")
needle="    /**\n     * @param array<int,string|int> $requestIds"
insert="    public static function setDefaultFetcherForTests(?callable $fetcher): void\n    {\n        if (PHP_SAPI !== 'cli') throw new RuntimeException('Test fetcher override is CLI-only.');\n        self::$testDefaultFetcher = $fetcher;\n    }\n\n"
if needle not in s: raise SystemExit('Google service insertion anchor missing')
s=s.replace(needle,insert+needle,1)
s=s.replace("    public const CONTACTS_COLUMN = 9;", "    public const CONTACTS_COLUMN = 9;\n    public const MAX_CSV_BYTES = 8 * 1024 * 1024;")
s=s.replace("            if (!is_string($payload) || $payload === '') throw new RuntimeException('Google Sheets CSV is empty.');\n            return $payload;", "            if (!is_string($payload) || $payload === '' || strlen($payload) > self::MAX_CSV_BYTES) throw new RuntimeException('Google Sheets CSV is empty or too large.');\n            return $payload;")
s=s.replace("        if (!is_string($payload) || $payload === '') throw new RuntimeException('Google Sheets CSV unavailable.');", "        if (!is_string($payload) || $payload === '' || strlen($payload) > self::MAX_CSV_BYTES) throw new RuntimeException('Google Sheets CSV unavailable or too large.');")
p.write_text(s,encoding='utf-8')

patch('app/View/pages/company_document_generator.php',
"                <div class=\"notice success mb-3\">Проверка пройдена. Документ можно сформировать.</div>\n                <div class=\"kv\">",
"                <div class=\"notice success mb-3\">Проверка пройдена. Документ можно сформировать.</div>\n                <?php if (!empty($preview['warnings'])): ?>\n                    <div class=\"notice warn mb-3\"><strong>Внешний источник данных:</strong><ul class=\"docgen-error-list mt-2\"><?php foreach ($preview['warnings'] as $warning): ?><li><?= e((string)$warning) ?></li><?php endforeach; ?></ul></div>\n                <?php endif; ?>\n                <div class=\"kv\">")
patch('app/View/pages/company_document_generator.php',
"                    <div class=\"kv-row\"><span class=\"kv-key\">Автомобиль</span><span class=\"kv-value\"><?= e(trim((string)($preview['snapshot']['vehicle']['model'] ?? '') . ' ' . (string)($preview['snapshot']['vehicle']['plate'] ?? '')) ?: '—') ?></span></div>",
"                    <?php $primaryVehicle = is_array($preview['snapshot']['vehicle'] ?? null) ? $preview['snapshot']['vehicle'] : []; ?>\n                    <div class=\"kv-row\"><span class=\"kv-key\"><?= e((string)($primaryVehicle['label'] ?? 'Транспортное средство')) ?></span><span class=\"kv-value\"><?= e(trim((string)($primaryVehicle['model'] ?? '') . ' · ' . (string)($primaryVehicle['plate'] ?? '')) ?: '—') ?><br><span class=\"text-muted\">VIN: <?= e(trim((string)($primaryVehicle['vin'] ?? '')) ?: '—') ?></span></span></div>\n                    <?php $secondaryVehicle = is_array($preview['snapshot']['trailer'] ?? null) ? $preview['snapshot']['trailer'] : []; ?>\n                    <?php if ((int)($secondaryVehicle['id'] ?? 0) > 0): ?>\n                        <div class=\"kv-row\"><span class=\"kv-key\"><?= e((string)($secondaryVehicle['label'] ?? 'Транспортная единица')) ?></span><span class=\"kv-value\"><?= e(trim((string)($secondaryVehicle['model'] ?? '') . ' · ' . (string)($secondaryVehicle['plate'] ?? '')) ?: '—') ?><br><span class=\"text-muted\">VIN: <?= e(trim((string)($secondaryVehicle['vin'] ?? '')) ?: '—') ?></span></span></div>\n                    <?php endif; ?>")

p=ROOT/'tests/document_generator_regression_v4_test.php'
s=p.read_text(encoding='utf-8')
s=s.replace("$has($wf,\"return \\$parts===[] ? '—'\",'empty request contacts dash');", "$has($wf,'GoogleSheetsRequestContactService','Google contacts wiring');$ok(!str_contains($access,'kontakt_tel'),'FEO contact columns removed from generator query');")
s=s.replace("$has($templateTrait,'contract_vehicle_lease_crew_v2.docx','built-in v2 seed');", "$has($templateTrait,'contract_vehicle_lease_crew_v3.docx','built-in v3 seed');")
s=s.replace("$has($templateTrait,'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa','built-in v2 SHA');", "$has($templateTrait,'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2','built-in v3 SHA');")
s=s.replace(" 'waybill_driver_assignment_v1.docx'=>'5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab'", " 'contract_vehicle_lease_crew_v3.docx'=>'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',\n 'waybill_driver_assignment_v1.docx'=>'5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab'")
s=s.replace("\"CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,brand VARCHAR(255),model VARCHAR(255),plate_number VARCHAR(64),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB\"", "\"CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,unit_type VARCHAR(32),brand VARCHAR(255),model VARCHAR(255),plate_number VARCHAR(64),vin VARCHAR(64),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB\"")
s=s.replace("\"CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB\"", "\"CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED,set_type VARCHAR(32),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB\"")
s=s.replace("INSERT INTO vehicle_units VALUES(1,'КАМАЗ','5490','А123АА77',101,'active',NULL),(2,'МАЗ','5440','В456ВВ78',202,'active',NULL)", "INSERT INTO vehicle_units VALUES(1,'truck','КАМАЗ','5490','А123АА77','XTCVIN00000000001',101,'active',NULL),(2,'truck','МАЗ','5440','В456ВВ78','XTCVIN00000000002',202,'active',NULL)")
s=s.replace("INSERT INTO vehicle_sets VALUES(1,1,NULL,101,'active',NULL),(2,2,NULL,202,'active',NULL)", "INSERT INTO vehicle_sets VALUES(1,1,NULL,'single',101,'active',NULL),(2,2,NULL,'single',202,'active',NULL)")
s=s.replace("$company=['id'=>$cid", "$googleFixture=\"header,c1,c2,c3,c4,c5,c6,c7,c8,contacts\\n300001,,,,,,,,,111\\n300002,,,,,,,,,\\\"222, two@test\\\"\\n300003,,,,,,,,,three@test\\n300004,,,,,,,,,\\n300005,,,,,,,,,555\\n\";App\\Service\\GoogleSheetsRequestContactService::setDefaultFetcherForTests(static fn(string $url):string=>$googleFixture);\n$company=['id'=>$cid",1)
s=s.replace("$eq($bt['vehicle_lease_crew']['sha256'],$canon['contract_vehicle_lease_crew_v2.docx'],'contract seed v2');", "$eq($bt['vehicle_lease_crew']['sha256'],$canon['contract_vehicle_lease_crew_v3.docx'],'contract seed v3');")
s=s.replace("$ok(!in_array('executor.bank_inn',$ph,true),'active v2 placeholder clean');", "$ok(!in_array('executor.bank_inn',$ph,true),'active v3 bank placeholder clean');$ok(in_array('vehicle.vin',$ph,true),'active v3 vehicle VIN placeholder');")
p.write_text(s,encoding='utf-8')

p=ROOT/'tests/document_generator_builtin_seed_resilience_test.php';s=p.read_text(encoding='utf-8');s=s.replace('contract_vehicle_lease_crew_v2.docx.b64.part001','contract_vehicle_lease_crew_v3.docx.b64.part001');p.write_text(s,encoding='utf-8')
patch('tests/document_generator_deploy_artifact_test.php',
"'contract_vehicle_lease_crew_v2.docx'=>'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',",
"'contract_vehicle_lease_crew_v2.docx'=>'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa',\n'contract_vehicle_lease_crew_v3.docx'=>'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2',")

print('DOCUMENT_GENERATOR_PATCH_BUILDER=PASS')
