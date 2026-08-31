<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

trait DocumentGeneratorDataTrait
{
    private static function normalizeParameters(string $type, array $params): array
    {
        if ($type==='vehicle_lease_crew') {
            return [
                'contract_number'=>trim((string)($params['contract_number'] ?? '')),
                'contract_date'=>trim((string)($params['contract_date'] ?? '')),
                'start_date'=>trim((string)($params['start_date'] ?? '')),
                'start_time'=>trim((string)($params['start_time'] ?? '')),
                'finish_date'=>trim((string)($params['finish_date'] ?? '')),
                'price'=>trim((string)($params['price'] ?? '')),
                'vat_mode'=>trim((string)($params['vat_mode'] ?? 'без НДС')),
                'executor_bank_inn'=>trim((string)($params['executor_bank_inn'] ?? '')),
            ];
        }
        return [
            'waybill_number'=>trim((string)($params['waybill_number'] ?? '')),
            'waybill_date'=>trim((string)($params['waybill_date'] ?? '')),
        ];
    }

    private static function validateParameters(string $type, array $params): array
    {
        $errors=[];
        $validDate = static function (string $value): bool {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m)) return false;
            return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
        };
        if ($type==='vehicle_lease_crew') {
            if (trim((string)($params['contract_number'] ?? ''))==='') $errors[]='Укажите номер договора.';
            foreach (['contract_date'=>'дату договора','start_date'=>'дату начала аренды','finish_date'=>'плановую дату завершения'] as $field=>$label) {
                $value=trim((string)($params[$field] ?? ''));
                if (!$validDate($value)) $errors[]='Укажите корректную ' . $label . '.';
            }
            $time=trim((string)($params['start_time'] ?? ''));
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) $errors[]='Время подачи должно быть в формате ЧЧ:ММ.';
            $price=str_replace(["\u{00A0}",' '],'',str_replace(',','.',trim((string)($params['price'] ?? ''))));
            if ($price==='' || !is_numeric($price) || (float)$price<0) $errors[]='Укажите корректную стоимость договора.';
            if (trim((string)($params['vat_mode'] ?? ''))==='') $errors[]='Укажите режим НДС.';
            $bankInn=preg_replace('/\D/','',trim((string)($params['executor_bank_inn'] ?? ''))) ?? '';
            if ($bankInn==='' || !in_array(strlen($bankInn),[10,12],true)) $errors[]='Укажите ИНН банка Арендодателя (10 или 12 цифр).';
        } elseif ($type==='waybill_driver_assignment') {
            if (trim((string)($params['waybill_number'] ?? ''))==='') $errors[]='Укажите номер путевого листа.';
            if (!$validDate(trim((string)($params['waybill_date'] ?? '')))) $errors[]='Укажите корректную дату путевого листа.';
        }
        return array_values(array_unique($errors));
    }

    private static function buildFixedValues(array $snapshot): array
    {
        $c=$snapshot['company'];$e=$snapshot['executor'];$d=$snapshot['driver'];$v=$snapshot['vehicle'];$t=$snapshot['trailer'];$p=$snapshot['parameters'];$s=$snapshot['requests_summary'] ?? [];
        $price=self::formatMoneyText((string)($p['price'] ?? ''));
        return [
            'contract.number'=>(string)($p['contract_number'] ?? ''),'contract.date'=>self::formatRuDate((string)($p['contract_date'] ?? '')),'contract.start_date'=>self::formatRuDate((string)($p['start_date'] ?? '')),'contract.start_time'=>(string)($p['start_time'] ?? ''),'contract.finish_date'=>self::formatRuDate((string)($p['finish_date'] ?? '')),'contract.price'=>$price,'contract.vat_mode'=>(string)($p['vat_mode'] ?? ''),
            'waybill.number'=>(string)($p['waybill_number'] ?? ''),'waybill.date'=>self::formatRuDate((string)($p['waybill_date'] ?? '')),
            'company.name'=>$c['name'],'company.inn'=>$c['inn'],'company.kpp_line'=>$c['kpp']!==''?'КПП: '.$c['kpp']:'','company.ogrn_line'=>$c['ogrn']!==''?'ОГРН: '.$c['ogrn']:'','company.legal_address'=>$c['legal_address'],'company.physical_address'=>$c['physical_address'],'company.bank_name'=>$c['bank_name'],'company.bank_account'=>$c['bank_account'],'company.bank_corr_account'=>$c['bank_corr_account'],'company.bank_bik'=>$c['bank_bik'],'company.contact_phone'=>$c['contact_phone'],'company.contact_email'=>$c['contact_email'],'company.director_full_name'=>$c['director_full_name'],'company.director_position'=>$c['director_position'],'company.signature_name'=>self::signatureName($c['director_full_name']),
            'executor.name'=>$e['name'],'executor.inn'=>$e['inn'],'executor.kpp_line'=>$e['kpp']!==''?'КПП: '.$e['kpp']:'','executor.ogrn_line'=>$e['ogrn']!==''?((strlen($e['ogrn'])===15?'ОГРНИП: ':'ОГРН: ').$e['ogrn']):'','executor.legal_address'=>$e['legal_address'],'executor.physical_address'=>$e['physical_address'],'executor.bank_name'=>$e['bank_name'],'executor.bank_account'=>$e['bank_account'],'executor.bank_corr_account'=>$e['bank_corr_account'],'executor.bank_bik'=>$e['bank_bik'],'executor.bank_inn'=>(string)($p['executor_bank_inn'] ?? ''),'executor.director_full_name'=>$e['director_full_name'],'executor.director_position'=>$e['director_position'],'executor.signature_name'=>$e['signature_name'],
            'driver.full_name'=>$d['full_name'],'driver.passport'=>$d['passport'],'driver.license'=>$d['license'],'driver.signature_name'=>$d['signature_name'],
            'vehicle.model'=>$v['model'],'vehicle.plate'=>$v['plate'],'trailer.display'=>$t['display'],
            'requests.count'=>(string)($s['count'] ?? count($snapshot['requests'] ?? [])),'requests.total_mass_kg'=>self::formatMassKg((float)($s['total_mass_kg'] ?? 0)),'requests.unload_address'=>(string)($s['unload_address'] ?? ''),
        ];
    }

    private static function validateFixedPlaceholders(array $placeholders, array $values, array $snapshot): array
    {
        $optional=[
            'executor.kpp_line','executor.ogrn_line','executor.physical_address','trailer.display',
        ];
        $requestPrefixes=['request.'];
        $errors=[];
        foreach ($placeholders as $name) {
            if (!is_string($name) || in_array($name,$optional,true)) continue;
            $isRequest=false;foreach($requestPrefixes as $prefix)if(str_starts_with($name,$prefix)){$isRequest=true;break;}
            if($isRequest)continue;
            if (!array_key_exists($name,$values)) { $errors[]='Для переменной {{'.$name.'}} не определён источник данных.'; continue; }
            if (trim((string)$values[$name])==='') $errors[]='Не хватает данных для {{'.$name.'}}.';
        }
        $company=$snapshot['company'];
        if (in_array('company.kpp_line',$placeholders,true) && trim((string)$company['kpp'])==='') $errors[]='У компании не заполнен КПП.';
        if (in_array('company.ogrn_line',$placeholders,true) && trim((string)$company['ogrn'])==='') $errors[]='У компании не заполнен ОГРН.';
        $executor=$snapshot['executor'];
        if (strlen(preg_replace('/\D/','',(string)$executor['inn']) ?? '')===10) {
            if (in_array('executor.kpp_line',$placeholders,true) && trim((string)$executor['kpp'])==='') $errors[]='У Арендодателя-юрлица не заполнен КПП.';
            if (in_array('executor.ogrn_line',$placeholders,true) && trim((string)$executor['ogrn'])==='') $errors[]='У Арендодателя-юрлица не заполнен ОГРН.';
        }
        return array_values(array_unique($errors));
    }

    private static function validateRequestRows(array $rows, array $placeholders): array
    {
        $errors=[];
        foreach ($rows as $row) {
            $id=(string)($row['zayavka_id'] ?? 'без ID');$items=[];
            if (in_array('request.mass_kg',$placeholders,true) && ($row['mass_netto'] ?? null)===null) $items[]='нет массы';
            if (in_array('request.sender',$placeholders,true) && trim((string)($row['naim_oo_gruzootpravitel'] ?? ''))==='') $items[]='нет грузоотправителя';
            if (in_array('request.loading_address',$placeholders,true) && trim((string)($row['mno_adres_pogruzki'] ?? ''))==='') $items[]='нет адреса погрузки';
            if (in_array('request.contacts',$placeholders,true) && self::requestContacts($row)==='') $items[]='отсутствуют контакты';
            if (in_array('requests.unload_address',$placeholders,true) && trim((string)($row['adres_vygruzki_otkhodov'] ?? ''))==='') $items[]='нет места выгрузки';
            if ($items!==[]) $errors[$id]=$items;
        }
        return $errors;
    }

    private static function unloadGroups(array $rows): array
    {
        $groups=[];
        foreach ($rows as $row) {
            $address=preg_replace('/\s+/u',' ',trim((string)($row['adres_vygruzki_otkhodov'] ?? ''))) ?? '';
            if ($address==='') continue;
            $groups[$address]=($groups[$address] ?? 0)+1;
        }
        return $groups;
    }

    private static function buildRequestPlaceholderRows(array $rows): array
    {
        $out=[];
        foreach ($rows as $index=>$row) {
            $kg=round(((float)($row['mass_netto'] ?? 0))*1000,3);
            $out[]=[
                'request.index'=>(string)($index+1),'request.id'=>(string)($row['zayavka_id'] ?? ''),'request.mass_kg'=>self::formatMassKg($kg),
                'request.sender'=>(string)($row['naim_oo_gruzootpravitel'] ?? ''),'request.loading_address'=>(string)($row['mno_adres_pogruzki'] ?? ''),'request.contacts'=>self::requestContacts($row),
            ];
        }
        return $out;
    }

    private static function formatRuDate(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)) return '';
        $months=['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая','06'=>'июня','07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября','11'=>'ноября','12'=>'декабря'];
        if (!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return '';
        return sprintf('«%s» %s %s г.',$m[3],$months[$m[2]] ?? $m[2],$m[1]);
    }

    private static function formatDateSimple(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)) return '';
        return $m[3].'.'.$m[2].'.'.$m[1];
    }

    private static function formatMoneyText(string $value): string
    {
        $normalized=str_replace(["\u{00A0}",' '],'',str_replace(',','.',$value));
        if ($normalized==='' || !is_numeric($normalized)) return $value;
        $number=(float)$normalized;
        $decimals=abs($number-round($number))<0.00001 ? 0 : 2;
        return number_format($number,$decimals,',',' ');
    }

    private static function signatureName(string $fullName): string
    {
        $parts=preg_split('/\s+/u',trim($fullName)) ?: [];
        if ($parts===[]) return '';
        $last=array_shift($parts);$initials='';
        foreach (array_slice($parts,0,2) as $part) { if ($part!=='' && preg_match('/^./us',$part,$m)) $initials.=$m[0].'.'; }
        return trim($last . ($initials!=='' ? ' '.$initials : ''));
    }

    private static function failed(array $errors): array
    {
        return ['ok'=>false,'errors'=>$errors,'request_errors'=>[],'missing_request_ids'=>[],'found_count'=>0,'total_mass_kg'=>0.0,'total_mass_kg_formatted'=>'0','unload_groups'=>[]];
    }

    private static function json(mixed $value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return $json;
    }
}
