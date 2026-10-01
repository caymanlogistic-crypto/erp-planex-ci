<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use PDO;
use Throwable;

/** Shared, tenant-local work cards. Files remain outside the public document root. */
final class RouteExecutorReviewCardService
{
    public function __construct(private readonly PDO $tenant, private readonly int $companyId) {}

    public function ready(): bool
    {
        $q=$this->tenant->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='route_executor_review_cards'");
        return (int)$q->fetchColumn()===1;
    }

    public function listing(): array
    {
        if (!$this->ready()) return [];
        return $this->tenant->query("SELECT c.card_id,c.actor_account_id,c.last_editor_account_id,c.revision,c.responsible_membership_id,c.trip_business_id,c.contractor_name,c.driver_name,c.driver_phone,c.vehicle_type,c.plate,c.updated_at,COALESCE(s.stage,'new') stage FROM route_executor_review_cards c LEFT JOIN route_executor_review_sends s ON s.card_id=c.card_id WHERE c.deleted_at IS NULL ORDER BY c.updated_at DESC,c.card_id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function load(string $id): ?array
    {
        if (!$this->ready()) throw new DomainException('Карточки ожидают обновления структуры базы данных (154).');
        $q=$this->tenant->prepare("SELECT c.*,COALESCE(s.stage,'new') stage FROM route_executor_review_cards c LEFT JOIN route_executor_review_sends s ON s.card_id=c.card_id WHERE c.card_id=? AND c.deleted_at IS NULL LIMIT 1");
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['snapshot']=json_decode((string)$row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $row['files']=json_decode((string)$row['files_json'],true,512,JSON_THROW_ON_ERROR);
        return $row;
    }

    public function save(string $id, int $actorId, int $expectedRevision, array $snapshot, array $uploads, array $keys): int
    {
        if (!$this->ready()) throw new DomainException('Карточки ожидают обновления структуры базы данных (154).');
        if ($expectedRevision<0) throw new DomainException('Некорректная версия карточки.');
        RouteExecutorReviewPackage::inspect($snapshot,[]);
        $clean=['controls'=>$snapshot['controls'],'logist'=>(string)($snapshot['logist']??''),
            'trip_id'=>(string)($snapshot['trip_id']??''),'trip_label'=>(string)($snapshot['trip_label']??'')];
        if (mb_strlen($clean['trip_label'])>600 || !preg_match('/^\d{0,20}$/D',$clean['logist']) || !preg_match('/^\d{0,20}$/D',$clean['trip_id'])) throw new DomainException('Некорректный логист или рейс.');
        $snapshotJson=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($snapshotJson)>262144) throw new DomainException('Карточка слишком большая.');
        $fields=[];
        foreach ($clean['controls'] as $item) {
            if (!is_array($item)) continue;
            $fields[(string)($item['formId']??'')][(string)($item['name']??'')]=(string)($item['value']??'');
        }
        $contractor=mb_substr(trim($fields['lab-contractor-form']['name']??''),0,255);
        $driver=mb_substr(trim($fields['lab-driver-form']['full_name']??''),0,255);
        $phone=mb_substr(trim($fields['lab-driver-form']['phone']??''),0,64);
        $vehicleType=mb_substr(trim($fields['lab-vehicle-form']['set_type']??''),0,40);
        $plate=mb_substr(trim($fields['lab-vehicle-form']['units[primary][plate_number]']??''),0,40);
        $newPaths=[];
        try {
            $files=$this->storeUploads($id,$uploads,$keys,$newPaths);
            $filesJson=json_encode($files,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->tenant->beginTransaction();
            $q=$this->tenant->prepare('SELECT revision,files_json,deleted_at FROM route_executor_review_cards WHERE card_id=? FOR UPDATE');
            $q->execute([$id]);$previous=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if ($previous && $previous['deleted_at']!==null) throw new DomainException('Карточка удалена.');
            $revision=$previous?(int)$previous['revision']:0;
            if ($revision!==$expectedRevision) throw new DomainException('Карточка изменена другим сотрудником. Обновите страницу.');
            $q=$this->tenant->prepare('SELECT stage FROM route_executor_review_sends WHERE card_id=? FOR UPDATE');
            $q->execute([$id]);$stage=(string)($q->fetchColumn()?:'new');
            if ($stage==='final' || str_contains($stage,'sending') || str_contains($stage,'unknown')) throw new DomainException('Карточка сейчас недоступна для изменения.');
            if ($previous) {
                $q=$this->tenant->prepare('UPDATE route_executor_review_cards SET last_editor_account_id=?,revision=revision+1,snapshot_json=?,files_json=?,responsible_membership_id=?,trip_business_id=?,contractor_name=?,driver_name=?,driver_phone=?,vehicle_type=?,plate=?,updated_at=NOW(6) WHERE card_id=?');
                $q->execute([$actorId,$snapshotJson,$filesJson,$clean['logist']!==''?(int)$clean['logist']:null,$clean['trip_id']!==''?(int)$clean['trip_id']:null,$contractor,$driver,$phone,$vehicleType,$plate,$id]);
            } else {
                $q=$this->tenant->prepare('INSERT INTO route_executor_review_cards(card_id,actor_account_id,last_editor_account_id,revision,snapshot_json,files_json,responsible_membership_id,trip_business_id,contractor_name,driver_name,driver_phone,vehicle_type,plate) VALUES(?,?,?,1,?,?,?,?,?,?,?,?,?)');
                $q->execute([$id,$actorId,$actorId,$snapshotJson,$filesJson,$clean['logist']!==''?(int)$clean['logist']:null,$clean['trip_id']!==''?(int)$clean['trip_id']:null,$contractor,$driver,$phone,$vehicleType,$plate]);
            }
            $this->tenant->commit();
            if ($previous) {
                foreach (json_decode((string)$previous['files_json'],true)?:[] as $old) {
                    try { $path=$this->absoluteFile((string)$old['path']); if (is_file($path)) unlink($path); } catch (Throwable) {}
                }
            }
            return $revision+1;
        } catch (Throwable $error) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            foreach ($newPaths as $path) if (is_file($path)) @unlink($path);
            throw $error;
        }
    }

    public function delete(string $id): void
    {
        if (!$this->ready()) throw new DomainException('Карточки ожидают обновления структуры базы данных (154).');
        $this->tenant->beginTransaction();
        try {
            $q=$this->tenant->prepare('SELECT card_id FROM route_executor_review_cards WHERE card_id=? AND deleted_at IS NULL FOR UPDATE');
            $q->execute([$id]);if (!$q->fetchColumn()) throw new DomainException('Карточка уже удалена.');
            $q=$this->tenant->prepare('SELECT stage FROM route_executor_review_sends WHERE card_id=? FOR UPDATE');
            $q->execute([$id]);$stage=(string)($q->fetchColumn()?:'new');
            if (str_contains($stage,'sending') || str_contains($stage,'unknown')) throw new DomainException('Нельзя удалить карточку, пока проверяется отправка.');
            $this->tenant->prepare('UPDATE route_executor_review_cards SET deleted_at=NOW(6),updated_at=NOW(6) WHERE card_id=?')->execute([$id]);
            $this->tenant->commit();
        } catch (Throwable $error) { if ($this->tenant->inTransaction()) $this->tenant->rollBack();throw $error; }
    }

    public function touch(string $id): void
    { if ($this->ready()) $this->tenant->prepare('UPDATE route_executor_review_cards SET updated_at=NOW(6) WHERE card_id=? AND deleted_at IS NULL')->execute([$id]); }

    public function storedFiles(array $card): array
    {
        $out=[];
        foreach ($card['files'] as $file) $out[]=['key'=>$file['formId'].'|'.$file['name'],'name'=>$file['original_name'],
            'tmp_name'=>$this->absoluteFile((string)$file['path']),'error'=>UPLOAD_ERR_OK,'size'=>(int)$file['size']];
        return $out;
    }

    public function file(array $card,string $fileId): array
    {
        foreach ($card['files'] as $file) if (hash_equals((string)$file['id'],$fileId)) {
            $file['absolute_path']=$this->absoluteFile((string)$file['path']);return $file;
        }
        throw new DomainException('Файл не найден.');
    }

    private function storeUploads(string $cardId,array $uploads,array $keys,array &$newPaths): array
    {
        if (!isset($uploads['name']) || !is_array($uploads['name'])) {
            if ($keys) throw new DomainException('Некорректные вложения.');
            return [];
        }
        if (count($uploads['name'])>50 || count($uploads['name'])!==count($keys)) throw new DomainException('Некорректные вложения.');
        $dir="companies/{$this->companyId}/route-executor-review/{$cardId}";
        $absoluteDir=storage_path($dir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0750,true) && !is_dir($absoluteDir)) throw new DomainException('Не удалось создать папку карточки.');
        $files=[];$total=0;
        foreach ($uploads['name'] as $i=>$original) {
            if (($uploads['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new DomainException('Не удалось загрузить документ.');
            $name=basename((string)$original);
            $size=(int)($uploads['size'][$i]??0);$total+=$size;
            if ($size<=0 || $size>20*1024*1024 || $total>18*1024*1024 || mb_strlen($name)>255 || !preg_match('/\.(pdf|jpe?g|png|webp|docx?|rtf|odt|xlsx?|csv|ods|gif|bmp|tiff?|heic|heif|txt)$/iD',$name)) throw new DomainException('Недопустимый документ или размер вложений.');
            if (!preg_match('/^(lab-(?:contractor|driver|vehicle)-form)\|([a-zA-Z0-9_\[\]-]{1,100})\|([0-9]{1,3})$/D',(string)$keys[$i],$m)) throw new DomainException('Некорректное поле документа.');
            $tmp=(string)($uploads['tmp_name'][$i]??'');
            if (!is_uploaded_file($tmp)) throw new DomainException('Не удалось загрузить документ.');
            $id=bin2hex(random_bytes(16));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            $path=$absoluteDir.DIRECTORY_SEPARATOR.$id.'.'.$ext;
            if (!move_uploaded_file($tmp,$path)) throw new DomainException('Не удалось сохранить документ.');
            $newPaths[]=$path;
            $files[]=['id'=>$id,'formId'=>$m[1],'name'=>$m[2],'index'=>(int)$m[3],
                'original_name'=>$name,'mime'=>(new \finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream',
                'size'=>$size,'path'=>$dir.'/'.$id.'.'.$ext];
        }
        return $files;
    }

    private function absoluteFile(string $relative): string
    {
        $root=realpath(storage_path("companies/{$this->companyId}/route-executor-review"));
        $path=realpath(storage_path($relative));
        if (!$root || !$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || !is_file($path)) throw new DomainException('Файл карточки недоступен.');
        return $path;
    }
}
