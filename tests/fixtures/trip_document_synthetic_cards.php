<?php
declare(strict_types=1);
// Only used by guarded disposable-DB tests. Never included by application routes.
function seedDocumentCards(PDO $pdo): void
{
    $pdo->exec("INSERT INTO clients(id,name,inn) VALUES(1,'Synthetic client','1111111111')");
    $pdo->exec("INSERT INTO contractors(id,name,inn,contractor_type,created_by_membership_id) VALUES(1,'Индивидуальный предприниматель Пинчук Сергей Борисович','9999999999','individual',11)");
    $pdo->exec("INSERT INTO contractor_contacts(contractor_id,contact_person,phone,email,is_primary,is_document_email) VALUES(1,'Тестовый контакт','+70000000000','carrier@example.test',1,1)");
    $pdo->exec("INSERT INTO drivers(id,full_name,phone,created_by_membership_id) VALUES(1,'Иванов Алексей Александрович','+70000000001',11)");
    $pdo->exec("INSERT INTO vehicle_units(id,plate_number,brand,unit_type,created_by_membership_id) VALUES(1,'А123АА51','Тестовая марка','single',11)");
    $pdo->exec("INSERT INTO vehicle_sets(id,set_type,primary_vehicle_unit_id,created_by_membership_id) VALUES(1,'single',1,11)");
    $pdo->exec("INSERT INTO driver_vehicle_blocks(id,driver_id,vehicle_set_id) VALUES(1,1,1)");
    $pdo->exec("INSERT INTO crews(id,contractor_id,vehicle_id,driver_id,driver_vehicle_block_id) VALUES(1,1,1,1,1)");
    $pdo->exec('INSERT INTO crew_drivers(crew_id,driver_id,position) VALUES(1,1,1)');
    $pdo->exec("INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,comment,created_by_membership_id) VALUES(123,255,1,1,1,'warehouse','executor_assigned','exact','2026-09-21','Ростов 10тн_1',11)");
}

function completeDocumentCards(PDO $pdo): void
{
    $pdo->exec("UPDATE contractors SET kpp='999999999',ogrn='9999999999999',director_full_name='Тестовый Руководитель',director_position='Директор',legal_address='Тестовый адрес',physical_address='Тестовый адрес',bank_account='99999999999999999999',bank_name='Тестовый банк',bank_bik='999999999',bank_corr_account='99999999999999999999',bank_inn='9999999999' WHERE id=1");
    $pdo->exec("INSERT INTO contractor_tax_history(contractor_id,tax_system,vat_mode,effective_from) VALUES(1,'osn','vat_20','2026-01-01')");
    $pdo->exec("UPDATE drivers SET email='driver@example.test',passport_number='0000 000000',passport_department_code='000-000',passport_issued_by='Тестовый орган',passport_issue_date='2020-01-01',license_number='0000000000',license_issue_date='2020-01-01',snils='00000000000' WHERE id=1");
    $pdo->exec("UPDATE vehicle_units SET model='Тестовая модель',vin='SYNTHETICVIN000001',capacity_tons=10,volume_m3=30 WHERE id=1");
}

function addSyntheticDocument(PDO $pdo,string $entity,string $code): int
{
    $labels=App\Service\TripDocumentPackage::LABELS;
    $dir=storage_path('companies/1/documents/synthetic');if(!is_dir($dir))mkdir($dir,0750,true);
    $name=$code.'_'.bin2hex(random_bytes(6)).'.pdf';$relative='companies/1/documents/synthetic/'.$name;
    file_put_contents(storage_path($relative),"%PDF-1.4\nSYNTHETIC ".$code."\n%%EOF");
    $q=$pdo->prepare("INSERT INTO documents(entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,status) VALUES(?,1,?,?,?,?, 'application/pdf',?,'uploaded')");
    $q->execute([$entity,$labels[$code],$name,$name,$relative,filesize(storage_path($relative))]);return (int)$pdo->lastInsertId();
}
