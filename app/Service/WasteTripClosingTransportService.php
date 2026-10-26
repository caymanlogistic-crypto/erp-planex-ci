<?php
declare(strict_types=1);

namespace App\Service;

use App\Security\AccessRuntime;
use DomainException;
use PDO;
use Throwable;

/** Diagnostic cards are stored on vehicle units, never copied into a trip. */
final class WasteTripClosingTransportService
{
    /** @return array<int,array{set_type:string,units:list<array<string,mixed>>}> */
    public static function forTrips(PDO $pdo, array $tripIds): array
    {
        if ($tripIds === []) return [];
        require_once base_path('app/Support/core_runtime.php');
        $marks=implode(',',array_fill(0,count($tripIds),'?'));
        $stmt=$pdo->prepare("SELECT wt.id trip_id,vs.id set_id,vs.set_type,
                responsible.membership_id responsible_membership_id,
                vu1.id primary_id,vu1.plate_number primary_plate,vu1.brand primary_brand,
                vu2.id secondary_id,vu2.plate_number secondary_plate,vu2.brand secondary_brand
            FROM waste_trips wt
            LEFT JOIN users responsible ON responsible.id=wt.responsible_user_id
            LEFT JOIN crews c ON c.id=wt.route_executor_id AND c.deleted_at IS NULL
            LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id AND dvb.deleted_at IS NULL
            LEFT JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id AND vs.deleted_at IS NULL
            LEFT JOIN vehicle_units vu1 ON vu1.id=vs.primary_vehicle_unit_id AND vu1.deleted_at IS NULL
            LEFT JOIN vehicle_units vu2 ON vu2.id=vs.secondary_vehicle_unit_id AND vu2.deleted_at IS NULL
            WHERE wt.id IN ($marks)");
        $stmt->execute($tripIds);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids=[];
        foreach ($rows as $row) foreach (['primary','secondary'] as $role) {
            $id=(int)($row[$role.'_id']??0);
            if ($id>0) $ids[$id]=$id;
        }
        $documents=[];
        if ($ids!==[]) {
            $docMarks=implode(',',array_fill(0,count($ids),'?'));
            $query=$pdo->prepare("SELECT d.id,d.entity_id,d.original_name FROM documents d
                JOIN document_types dt ON dt.id=d.document_type_id AND dt.code='diagnostic_card'
                WHERE d.entity_type='vehicle_unit' AND d.deleted_at IS NULL AND d.entity_id IN ($docMarks)
                ORDER BY d.id");
            $query->execute(array_values($ids));
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $doc) $documents[(int)$doc['entity_id']][]=$doc;
        }
        $result=[];$editable=[];
        $closingMembershipId=AccessRuntime::current()->isActive()
            && WasteTripClosingService::isRegistryRoute()
            && AccessControlService::canAccessPage('company.documents.trip_closing_registry')
            ? AccessControlService::effectiveMembershipId() : null;
        $rules=vehicleSetTypeRules();
        foreach ($rows as $row) {
            $setType=(string)($row['set_type']??'');
            if (!isset($rules[$setType])) continue;
            $units=[];
            foreach ($rules[$setType]['units'] as $role=>$rule) {
                $unitId=(int)($row[$role.'_id']??0);
                if ($unitId<=0) continue;
                $accessKey=(int)$row['set_id'].':'.$unitId;
                $editable[$accessKey]??=self::canEditUnit($pdo,(int)$row['set_id'],$unitId);
                $canUpload=$editable[$accessKey]
                    || ($closingMembershipId!==null && $closingMembershipId>0
                        && $closingMembershipId===(int)($row['responsible_membership_id']??0));
                $units[]=[
                    'id'=>$unitId,'role'=>$role,'label'=>(string)$rule['label'],
                    'plate'=>(string)($row[$role.'_plate']??''),'brand'=>(string)($row[$role.'_brand']??''),
                    'has_card'=>!empty($documents[$unitId]),'can_upload'=>$canUpload,
                    'documents'=>$canUpload?($documents[$unitId]??[]):[],
                ];
            }
            $result[(int)$row['trip_id']]=['set_type'=>$setType,'set_label'=>(string)$rules[$setType]['label'],'units'=>$units];
        }
        return $result;
    }

    public static function addDiagnosticCard(PDO $pdo, int $companyId, int $tripId, int $unitId,
        array $files, int $userId, string $role): void
    {
        WasteTripClosingService::trip($pdo,$tripId);
        if ($files===[] || count($files)>10) throw new DomainException('Выберите от одного до десяти файлов диагностической карты.');
        foreach ($files as $file) {
            if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) throw new DomainException('Не удалось загрузить файл диагностической карты.');
            if ((int)($file['size']??0)<=0 || (int)$file['size']>20*1024*1024) throw new DomainException('Размер каждого файла должен быть не больше 20 МБ.');
            if (!in_array(strtolower(pathinfo(basename((string)($file['name']??'')),PATHINFO_EXTENSION)),['pdf','jpg','jpeg','png','webp','tif','tiff','doc','docx'],true)) throw new DomainException('Недопустимый формат диагностической карты.');
        }
        $relativeDir='companies/'.$companyId.'/documents/vehicle_unit/'.$unitId;
        $absoluteDir=storage_path($relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0700,true) && !is_dir($absoluteDir)) throw new DomainException('Хранилище файлов недоступно.');
        $paths=[];
        try {
            $pdo->beginTransaction();
            $tripLock=$pdo->prepare("SELECT id FROM waste_trips wt WHERE id=? AND deleted_at IS NULL AND ".WasteTripClosingService::allowedStatusesSql()." FOR UPDATE");
            $tripLock->execute([$tripId]);
            if (!$tripLock->fetchColumn()) throw new DomainException('Рейс изменился. Обновите страницу.');
            WasteTripClosingService::assertEditable($pdo,$tripId);
            $transport=self::forTrips($pdo,[$tripId])[$tripId]??null;
            $unit=null;
            foreach ($transport['units']??[] as $item) if ((int)$item['id']===$unitId) $unit=$item;
            if ($unit===null || empty($unit['can_upload'])) throw new DomainException('Транспортная единица недоступна для изменения.');
            $lock=$pdo->prepare('SELECT id FROM vehicle_units WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$unitId]);
            if (!$lock->fetchColumn()) throw new DomainException('Транспорт изменился. Обновите страницу.');
            $typeId=LocalMigrationService::ensureDocumentTypeRecord($pdo,'Диагностическая карта','diagnostic_card','vehicle_unit');
            if (!$typeId) throw new DomainException('Тип документа недоступен.');
            foreach ($files as $file) {
                $name=basename((string)$file['name']);
                $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                $stored=bin2hex(random_bytes(16)).'.'.$extension;
                $path=$absoluteDir.DIRECTORY_SEPARATOR.$stored;
                if (!move_uploaded_file((string)$file['tmp_name'],$path)) throw new DomainException('Не удалось сохранить файл диагностической карты.');
                $paths[]=$path;
                $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
                $insert=$pdo->prepare('INSERT INTO documents (entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $insert->execute(['vehicle_unit',$unitId,'Диагностическая карта',$typeId,$name,$stored,$relativeDir.'/'.$stored,$mime,(int)$file['size'],'uploaded',$userId?:null,$role,$userId?:null,$role]);
            }
            $pdo->prepare("INSERT INTO waste_trip_closing_reviews (waste_trip_id,state,version) VALUES (?,'work',1) ON DUPLICATE KEY UPDATE state='work',version=version+1")
                ->execute([$tripId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($paths as $path) @unlink($path);
            throw $error;
        }
    }

    private static function canEditUnit(PDO $pdo, int $setId, int $unitId): bool
    {
        if (AccessRuntime::current()->isActive()) {
            if (!AccessControlService::canAccessPage('company.vehicle_sets')) return false;
            return RegulatedDataScopeService::canAccess($pdo,'vehicle_unit','vehicle_units',$unitId);
        }
        $role=(string)($_SESSION['role_code']??'');
        if (in_array($role,['superadmin','company_owner','senior_logist'],true)) return true;
        $userId=(int)($_SESSION['user_id']??0);
        if ($role!=='logist' || $userId<=0) return false;
        $query=$pdo->prepare("SELECT 1 FROM vehicle_sets vs WHERE vs.id=? AND (vs.created_by_user_id=? OR EXISTS (
            SELECT 1 FROM entity_access_grants g WHERE g.entity_type='vehicle_set' AND g.entity_id=vs.id
                AND g.granted_to_user_id=? AND g.access_level='edit' AND g.revoked_at IS NULL)) LIMIT 1");
        $query->execute([$setId,$userId,$userId]);
        return (bool)$query->fetchColumn();
    }
}
