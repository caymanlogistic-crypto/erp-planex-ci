<?php
declare(strict_types=1);

namespace App\Service;

/** Display-only values for the route executor list. Source records remain unchanged. */
final class RouteExecutorListPresentation
{
    public static function reviewFields(string $snapshotJson): array
    {
        $snapshot=json_decode($snapshotJson,true);
        $fields=[];
        foreach (($snapshot['controls']??[]) as $control) {
            if (!is_array($control)) continue;
            $fields[(string)($control['formId']??'')][(string)($control['name']??'')]=(string)($control['value']??'');
        }
        $contractor=$fields['lab-contractor-form']??[];
        $driver=$fields['lab-driver-form']??[];
        $vehicle=$fields['lab-vehicle-form']??[];
        $contactIndex=0;
        foreach ($contractor as $name=>$value) {
            if (preg_match('/^contacts\[(\d+)\]\[phone\]$/',$name,$match) && trim($value)!=='') {
                $contactIndex=(int)$match[1];
                break;
            }
        }
        return [
            'contractor_phone'=>$contractor["contacts[$contactIndex][phone]"]??'',
            'contractor_email'=>$contractor["contacts[$contactIndex][email]"]??'',
            'driver_email'=>$driver['email']??'',
            'primary_brand'=>$vehicle['units[primary][brand]']??'',
            'primary_plate'=>$vehicle['units[primary][plate_number]']??'',
            'primary_capacity'=>$vehicle['units[primary][capacity_tons]']??'',
            'primary_volume'=>$vehicle['units[primary][volume_m3]']??'',
            'secondary_brand'=>$vehicle['units[secondary][brand]']??'',
            'secondary_plate'=>$vehicle['units[secondary][plate_number]']??'',
            'secondary_capacity'=>$vehicle['units[secondary][capacity_tons]']??'',
            'secondary_volume'=>$vehicle['units[secondary][volume_m3]']??'',
        ];
    }

    public static function plate(?string $plate, bool $trailer=false): string
    {
        $raw=trim((string)$plate);
        $compact=mb_strtoupper((string)preg_replace('/[\s\-]+/u','',$raw),'UTF-8');
        if ($trailer && preg_match('/^([А-ЯA-Z]{1,2})(\d{4})(\d{2,3})$/u',$compact,$m)) return $m[1].$m[2].' '.$m[3];
        if (!$trailer && preg_match('/^([А-ЯA-Z])(\d{3})([А-ЯA-Z]{2})(\d{2,3})$/u',$compact,$m)) return $m[1].' '.$m[2].' '.$m[3].' '.$m[4];
        return $raw;
    }

    public static function metric(?string $value): string
    {
        $value=trim((string)$value);
        if ($value==='') return '';
        if (is_numeric($value)) $value=(string)(float)$value;
        return str_replace('.',',',$value);
    }
}
