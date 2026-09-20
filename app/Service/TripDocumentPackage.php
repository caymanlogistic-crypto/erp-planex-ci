<?php
declare(strict_types=1);
namespace App\Service;

/** Completeness reads the existing cards and explicitly classified documents only. */
final class TripDocumentPackage
{
    public const LABELS=['passport'=>'Паспорт','driver_license'=>'Водительское удостоверение','snils'=>'СНИЛС','sts'=>'СТС','photo'=>'Фотография','diagnostic_card'=>'Диагностическая карта','company_card'=>'Карточка предприятия','inn_cert'=>'Свидетельство ИНН','ogrn_cert'=>'Свидетельство ОГРН','contract'=>'Договор'];

    public static function inspect(array $trip,array $cards,int $companyId): array
    {
        $rows=[];$facts=[];$files=[];$objects=[];
        $add=static function(string $group,string $label,bool $present,string $stage='minimum')use(&$rows):void {$rows[]=['group'=>$group,'label'=>$label,'present'=>$present,'stage'=>$stage];};
        $fields=static function(string $group,array $record,array $labels,?string $key=null)use($add,&$facts):void {
            foreach($labels as $field=>$label){$value=trim((string)($record[$field]??''));$add($group,$label,$value!=='');if($key!==null)$facts[$key.'.'.$field]=['label'=>$group.' · '.$label,'value'=>$value];}
        };
        $add('Рейс','Исполнитель рейса',!empty($cards['crew']));
        $c=$cards['contractor']??[];$type=$c['contractor_type']??'';
        $add('Подрядчик','Тип подрядчика',in_array($type,['legal_entity','individual','self_employed','private_person'],true));
        $fields('Подрядчик',$c,['name'=>'Наименование','inn'=>'ИНН','legal_address'=>'Юридический адрес','physical_address'=>'Фактический адрес','bank_account'=>'Расчётный счёт','bank_name'=>'Банк','bank_bik'=>'БИК','bank_corr_account'=>'Корреспондентский счёт','bank_inn'=>'ИНН банка']);
        if($type==='legal_entity')$fields('Подрядчик',$c,['kpp'=>'КПП','director_full_name'=>'Руководитель','director_position'=>'Должность руководителя']);
        if(in_array($type,['legal_entity','individual'],true))$fields('Подрядчик',$c,['ogrn'=>'ОГРН / ОГРНИП']);
        if($type!=='private_person')$fields('Подрядчик',$c,['tax_system'=>'Система налогообложения','vat_mode'=>'НДС']);
        $contact=false;
        foreach($cards['contacts'] as $row)if(trim((string)$row['contact_person'])!=='' && trim((string)$row['phone'])!=='' && filter_var($row['email'],FILTER_VALIDATE_EMAIL) && $row['is_document_email'])$contact=true;
        $add('Подрядчик','Контакт для документов: имя, телефон, email',$contact,'full');
        if($c)$objects['contractor:'.$c['id']]=['title'=>'Подрядчик','codes'=>['company_card','inn_cert','ogrn_cert','contract']];
        $add('Водитель','Назначен хотя бы один водитель',count($cards['drivers'])>0);
        foreach($cards['drivers'] as $d){
            $key='driver:'.$d['id'];$group='Водитель · '.($d['full_name']?:'#'.$d['id']);
            $fields($group,$d,['full_name'=>'ФИО','passport_number'=>'Серия и номер паспорта','passport_department_code'=>'Код подразделения','passport_issued_by'=>'Кем выдан паспорт','passport_issue_date'=>'Дата выдачи паспорта','license_number'=>'Номер ВУ','license_issue_date'=>'Дата выдачи ВУ','snils'=>'СНИЛС'],$key);
            $main=trim((string)($d['phone']??''));$extras=[];
            foreach($d['phones'] as $phone){if($phone['is_main'])$main=$phone['phone'];elseif(trim($phone['phone'])!=='')$extras[]=$phone['phone'];}
            $add($group,'Основной телефон',$main!=='');$facts[$key.'.phone']=['label'=>$group.' · Телефон','value'=>$main];
            if($extras)$facts[$key.'.extra']=['label'=>$group.' · Дополнительные телефоны','value'=>implode(', ',$extras)];
            $add($group,'Email',(bool)filter_var($d['email']??'',FILTER_VALIDATE_EMAIL));$facts[$key.'.email']=['label'=>$group.' · Email','value'=>$d['email']??''];
            $objects[$key]=['title'=>$group,'codes'=>['passport','driver_license','snils']];
        }
        $set=$cards['set']??[];$setType=$set['set_type']??'';
        $add('ТС','Тип комплекта',in_array($setType,['single','coupling','road_train'],true));
        $add('ТС','Все части комплекта',count($cards['vehicles'])===($setType==='single'?1:2));
        $facts['set.type']=['label'=>'ТС · Тип комплекта','value'=>['single'=>'Одиночка','coupling'=>'Сцепка','road_train'=>'Автопоезд'][$setType]??''];
        foreach($cards['vehicles'] as $unit){
            $key='vehicle_unit:'.$unit['id'];$primary=(int)$unit['id']===(int)($set['primary_vehicle_unit_id']??0);
            $group=($primary?($setType==='coupling'?'Тягач':'Машина'):($setType==='coupling'?'Полуприцеп':'Прицеп')).' · '.($unit['plate_number']?:'#'.$unit['id']);
            $fields($group,$unit,['brand'=>'Марка','model'=>'Модель','plate_number'=>'Госномер','vin'=>'VIN'],$key);
            if(!($setType==='coupling' && $primary))foreach(['capacity_tons'=>'Грузоподъёмность, т','volume_m3'=>'Объём, м³'] as $field=>$label){$add($group,$label,(float)($unit[$field]??0)>0);$facts[$key.'.'.$field]=['label'=>$group.' · '.$label,'value'=>(string)($unit[$field]??'')];}
            foreach(['diagnostic_card_number'=>'Номер диагностической карты','diagnostic_card_date'=>'Дата диагностической карты'] as $field=>$label)$add($group,$label,trim((string)($unit[$field]??''))!=='','pr');
            $objects[$key]=['title'=>$group,'codes'=>['sts','photo','diagnostic_card']];
        }
        foreach($cards['documents'] as $doc){
            $object=$doc['entity_type'].':'.$doc['entity_id'];if(!isset($objects[$object]))continue;
            $code=$doc['type_code']??'';
            if($code==='')$code=array_search($doc['document_type'],self::LABELS,true)?:'';
            if(!in_array($code,$objects[$object]['codes'],true))continue;
            $path=DocumentService::resolveStoredPath($companyId,(string)$doc['relative_path']);if(!$path)continue;
            if(DocumentService::validateUploadedFile(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$path,'name'=>$doc['original_name'],'size'=>filesize($path)],false))continue;
            $extension=strtolower(pathinfo($doc['original_name'],PATHINFO_EXTENSION));
            if(!preg_match('/^[a-z0-9]{1,12}$/D',$extension))continue;
            $files[]=['document_id'=>(int)$doc['id'],'object'=>$object,'code'=>$code,'name'=>DocumentMailMime::filename(self::LABELS[$code].'_'.str_replace([' · ',' '],['_','_'],$objects[$object]['title']).'_'.$doc['id'].'.'.$extension),
                'path'=>$doc['relative_path'],'sha256'=>hash_file('sha256',$path),'size'=>filesize($path),'mime'=>DocumentService::responseMime($path)];
        }
        foreach($objects as $object=>$config)foreach($config['codes'] as $code){
            $present=(bool)array_filter($files,static fn(array $f):bool=>$f['object']===$object && $f['code']===$code);
            $stage=in_array($code,['passport','driver_license','sts'],true)?'minimum':($code==='photo'?'full':'pr');
            $add($config['title'],self::LABELS[$code],$present,$stage);
        }
        $minimum=array_values(array_filter($rows,static fn(array $r):bool=>!$r['present'] && $r['stage']==='minimum'));
        $full=array_values(array_filter($rows,static fn(array $r):bool=>!$r['present'] && $r['stage']!=='pr'));
        return ['rows'=>$rows,'minimum_missing'=>$minimum,'full_missing'=>$full,'facts'=>$facts,'files'=>$files];
    }

    public static function body(array $trip,array $state,string $stage,?array $previous=null): string
    {
        $lines=[$stage==='full'?'ПОЛНЫЕ ДАННЫЕ':'МИНИМАЛЬНЫЙ ПАКЕТ','Рейс #'.$trip['number'],'Загрузка: '.($trip['loading_dates']?:'Не указана'),'Ответственный Л: '.$trip['responsible']['full_name']];
        foreach($trip['points'] as $point)$lines[]=($point['point_type']==='loading'?'Загрузка: ':'Выгрузка: ').$point['address_text'];
        $lines[]='';if($previous)$lines[]='Дополнение к ранее отправленному минимальному пакету.';
        foreach($state['facts'] as $key=>$fact)if(!$previous || ($previous['facts'][$key]??null)!==$fact)$lines[]=$fact['label'].': '.($fact['value']?:'Не указано');
        if($previous)foreach($previous['facts'] as $key=>$fact)if(!isset($state['facts'][$key]))$lines[]=$fact['label'].': больше не относится к текущему комплекту рейса.';
        if($stage==='minimum'){
            $lines[]='';$lines[]='До полного пакета осталось:';
            foreach($state['full_missing'] as $row)$lines[]='— '.$row['group'].': '.$row['label'];
        }
        return implode("\n",$lines);
    }

    public static function freeze(array $files,int $companyId,int $requestId,string $operation): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$operation))throw new \LogicException('Invalid package identity');
        $dir='companies/'.$companyId.'/documents/packages/'.$requestId.'/'.$operation;
        if(!is_dir(storage_path($dir)) && !mkdir(storage_path($dir),0750,true) && !is_dir(storage_path($dir)))throw new \RuntimeException('Package storage unavailable');
        foreach($files as &$file){
            $source=DocumentService::resolveStoredPath($companyId,$file['path']);
            if(!$source || !hash_equals($file['sha256'],hash_file('sha256',$source)))throw new \DomainException('Документы изменились. Обновите предпросмотр пакета.');
            $target=$dir.'/'.$file['document_id'].'-'.$file['sha256'].'.'.pathinfo($file['name'],PATHINFO_EXTENSION);
            if(!copy($source,storage_path($target)) || !hash_equals($file['sha256'],hash_file('sha256',storage_path($target))))throw new \DomainException('Не удалось сохранить проверенный пакет. Повторите предпросмотр.');
            $file['path']=$target;
        }unset($file);
        return $files;
    }

    public static function wireAttachments(array $files,int $companyId): array
    {
        $result=[];
        foreach($files as $file){$path=DocumentService::resolveStoredPath($companyId,$file['path']);if(!$path || !hash_equals($file['sha256'],hash_file('sha256',$path)))throw new \DomainException('Сохранённый пакет недоступен. Отправка остановлена.');$result[]=['name'=>$file['name'],'mime'=>$file['mime'],'bytes'=>file_get_contents($path)];}
        return $result;
    }
}
