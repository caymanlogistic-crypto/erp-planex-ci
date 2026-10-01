<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;

/** Validates the review package independently of browser readiness indicators. */
final class RouteExecutorReviewPackage
{
    public static function inspect(array $snapshot, array $files, bool $storedFiles=false): array
    {
        $controls=$snapshot['controls']??null;
        if (!is_array($controls) || count($controls)>350) throw new DomainException('Некорректные данные карточки.');
        $values=[];$rows=[];
        $sections=['lab-contractor-form'=>'Подрядчик','lab-driver-form'=>'Водитель','lab-vehicle-form'=>'Транспорт'];
        foreach ($controls as $item) {
            if (!is_array($item)) throw new DomainException('Некорректные данные карточки.');
            $form=(string)($item['formId']??'');$name=(string)($item['name']??'');
            if (!isset($sections[$form]) || !preg_match('/^[a-zA-Z0-9_\[\]-]{1,100}$/D',$name)) throw new DomainException('Некорректное поле карточки.');
            $value=$item['value']??'';
            if (!is_string($value) || mb_strlen($value)>5000) throw new DomainException('Значение поля слишком длинное.');
            $values[$form][$name]=$value;
            if (trim($value)==='' || in_array((string)($item['type']??''),['checkbox','radio'],true)) continue;
            $label=(string)($item['label']??$name);
            if ($form==='lab-driver-form' && $name==='extra_phones[]') $label='Доп контакты';
            if ($form==='lab-driver-form' && $name==='extra_phone_comments[]') $label='Комментарий к доп контактам';
            if (mb_strlen($label)>120 || preg_match('/[\r\n]/',$label)) $label=$name;
            $display=$item['displayValue']??$value;
            if (!is_string($display) || mb_strlen($display)>5000) $display=$value;
            $rows[]=['name'=>$sections[$form].': '.$label,'label'=>$label,'form'=>$form,'field'=>$name,'raw_value'=>$value,'value'=>$display];
        }
        $get=static fn(string $form,string $name):string=>trim((string)($values[$form][$name]??''));
        $contract=$values['lab-contractor-form']??[];$driver=$values['lab-driver-form']??[];$vehicle=$values['lab-vehicle-form']??[];
        $type=$get('lab-contractor-form','contractor_type');
        $contractFields=['name','inn','contractor_type','ogrn','director_full_name','director_position','legal_address','bank_account','bank_bik','bank_name','bank_corr_account'];
        if ($type==='legal_entity') $contractFields[]='kpp';
        $contractReady=$type!=='';
        foreach ($contractFields as $field) $contractReady=$contractReady && trim((string)($contract[$field]??''))!=='';
        $contactRows=[];
        foreach (array_keys($contract) as $name) {
            if (preg_match('/^contacts\[(\d+)\]\[(contact_person|phone|email)\]$/D',$name,$match)) $contactRows[(int)$match[1]]=true;
        }
        if (!$contactRows) $contractReady=false;
        foreach (array_keys($contactRows) as $index) {
            foreach (['contact_person','phone','email'] as $field) {
                $contractReady=$contractReady && trim((string)($contract["contacts[$index][$field]"]??''))!=='';
            }
        }
        $trainingReady=$get('lab-driver-form','full_name')!=='' && $get('lab-driver-form','snils')!=='';
        $driverFields=['full_name','passport_number','passport_issued_by','passport_issue_date','license_number','license_issue_date','email'];
        $vehicleReady=true;
        foreach ($driverFields as $field) $vehicleReady=$vehicleReady && trim((string)($driver[$field]??''))!=='';
        $setType=$get('lab-vehicle-form','set_type');
        $roles=match ($setType) {
            'single'=>'primary',
            'coupling','road_train'=>'primary,secondary',
            default=>'',
        };
        if ($roles==='') $vehicleReady=false;
        $requiredFiles=['lab-driver-form|predef_doc[passport][]','lab-driver-form|predef_doc[driver_license][]'];
        foreach (array_filter(explode(',',$roles)) as $role) {
            foreach (['brand','plate_number','vin','capacity_tons','volume_m3'] as $field) {
                $vehicleReady=$vehicleReady && $get('lab-vehicle-form',"units[$role][$field]")!=='';
            }
            $requiredFiles[]="lab-vehicle-form|predef_doc[$role][sts][]";
            $requiredFiles[]="lab-vehicle-form|predef_doc[$role][photo][]";
        }
        $seen=[];$attachments=[];$totalBytes=0;
        if (count($files)>50) throw new DomainException('Слишком много файлов.');
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['key'],$file['name'],$file['tmp_name'],$file['error'],$file['size'])) throw new DomainException('Некорректный файл.');
            if ((int)$file['error']!==UPLOAD_ERR_OK || (int)$file['size']<=0 || (int)$file['size']>20*1024*1024
                || !($storedFiles ? is_file((string)$file['tmp_name']) : is_uploaded_file((string)$file['tmp_name']))) throw new DomainException('Не удалось загрузить файл.');
            $totalBytes+=(int)$file['size'];
            if ($totalBytes>18*1024*1024) throw new DomainException('Общий размер файлов превышает допустимый размер письма.');
            $key=(string)$file['key'];$name=basename((string)$file['name']);
            if (!preg_match('/^lab-(contractor|driver|vehicle)-form\|[a-zA-Z0-9_\[\]-]{1,100}$/D',$key)
                || !preg_match('/\.(pdf|jpe?g|png|webp|docx?|rtf|odt|xlsx?|csv|ods|gif|bmp|tiff?|heic|heif|txt)$/iD',$name)) throw new DomainException('Неподдерживаемый файл.');
            $seen[$key]=true;
            $bytes=file_get_contents((string)$file['tmp_name']);
            if ($bytes===false) throw new DomainException('Не удалось прочитать файл.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)?:'application/octet-stream';
            $attachments[]=['key'=>$key,'name'=>$name,'bytes'=>$bytes,'mime'=>$mime];
        }
        foreach ($requiredFiles as $key) $vehicleReady=$vehicleReady && isset($seen[$key]);
        $missing=[];
        $labels=['name'=>'Наименование подрядчика','inn'=>'ИНН','contractor_type'=>'Тип перевозчика','ogrn'=>'ОГРН / ОГРНИП',
            'director_full_name'=>'Руководитель','director_position'=>'Должность руководителя','legal_address'=>'Юридический адрес',
            'bank_account'=>'Расчётный счёт','bank_bik'=>'БИК','bank_name'=>'Банк','bank_corr_account'=>'Корреспондентский счёт','kpp'=>'КПП'];
        foreach ($contractFields as $field) if (trim((string)($contract[$field]??''))==='') $missing[]='Подрядчик: '.($labels[$field]??$field);
        if (!$contactRows) $missing[]='Подрядчик: контактное лицо, телефон и Email';
        foreach (array_keys($contactRows) as $index) foreach (['contact_person'=>'контактное лицо','phone'=>'телефон контакта','email'=>'Email контакта'] as $field=>$label)
            if (trim((string)($contract["contacts[$index][$field]"]??''))==='') $missing[]='Подрядчик: '.$label;
        foreach (['full_name'=>'ФИО водителя','snils'=>'СНИЛС водителя','passport_number'=>'Серия и номер паспорта',
            'passport_issued_by'=>'Кем выдан паспорт','passport_issue_date'=>'Дата выдачи паспорта',
            'license_number'=>'Номер водительского удостоверения','license_issue_date'=>'Дата выдачи водительского удостоверения',
            'email'=>'Email водителя'] as $field=>$label) if ($get('lab-driver-form',$field)==='') $missing[]='Водитель: '.$label;
        if ($roles==='') $missing[]='Транспорт: тип комплекта ТС';
        foreach (array_filter(explode(',',$roles)) as $role) foreach (['brand'=>'марка','plate_number'=>'госномер','vin'=>'VIN',
            'capacity_tons'=>'грузоподъёмность','volume_m3'=>'объём'] as $field=>$label)
            if ($get('lab-vehicle-form',"units[$role][$field]")==='') $missing[]='Транспорт '.($role==='primary'?'основной':'дополнительный').': '.$label;
        foreach ($requiredFiles as $key) if (!isset($seen[$key])) {
            $label=match (true) {
                str_contains($key,'driver_license')=>'Водительское удостоверение',str_contains($key,'passport')=>'Паспорт водителя',
                str_contains($key,'[sts]')=>'СТС',str_contains($key,'[photo]')=>'Фото ТС',default=>'Документ ТС'};
            $missing[]='Файл: '.$label.(str_contains($key,'[secondary]')?' (дополнительный ТС)':'');
        }
        $ready=array_values(array_filter([
            $contractReady?'Подписание договора':null,
            $trainingReady?'Обучение водителя':null,
            $vehicleReady?'Регистрация транспорта':null,
        ]));
        return ['ready'=>$ready,'contract_ready'=>$contractReady,'full'=>$contractReady && $trainingReady && $vehicleReady,
            'rows'=>$rows,'attachments'=>$attachments,'missing'=>array_values(array_unique($missing)),
            'contractor'=>$get('lab-contractor-form','name'),'driver'=>$get('lab-driver-form','full_name'),
            'plate'=>$get('lab-vehicle-form','units[primary][plate_number]'),
            'secondary_plate'=>$get('lab-vehicle-form','units[secondary][plate_number]')];
    }
}
