<?php
declare(strict_types=1);

namespace App\Service;

final class RouteExecutorReviewMaxMessage
{
    public static function values(array $package, string $trip, string $logist): array
    {
        $surname=preg_split('/\s+/u',trim((string)($package['driver']??'')))[0]??'';
        $vehicle=trim((string)($package['plate']??''));
        if ($surname!=='') $vehicle.=($vehicle!==''?' ':'').'('.$surname.')';
        return [
            'trip'=>$trip,
            'vehicle_driver'=>$vehicle?:'—',
            'responsible'=>self::firstName($logist),
            'missing'=>self::missing((array)($package['missing']??[])),
        ];
    }

    private static function firstName(string $name): string
    {
        $parts=preg_split('/\s+/u',trim($name))?:[];
        return $parts[1]??$parts[0]??'—';
    }

    public static function missing(array $items): string
    {
        $groups=['Подрядчик'=>[],'Реквизиты'=>[],'Контакт'=>[],'Водитель'=>[],'ТС'=>[],'Файлы'=>[]];
        foreach ($items as $item) {
            $item=(string)$item;
            if (str_starts_with($item,'Подрядчик: ')) {
                $label=substr($item,strlen('Подрядчик: '));
                $group=match (true) {
                    in_array($label,['Расчётный счёт','БИК','Банк','Корреспондентский счёт'],true)=>'Реквизиты',
                    str_contains(mb_strtolower($label),'контакт') || str_contains(mb_strtolower($label),'email')=>'Контакт',
                    default=>'Подрядчик',
                };
            } elseif (str_starts_with($item,'Водитель: ')) {
                $group='Водитель';$label=substr($item,strlen('Водитель: '));
            } elseif (str_starts_with($item,'Транспорт: ') || str_starts_with($item,'Транспорт основной: ') || str_starts_with($item,'Транспорт дополнительный: ')) {
                $group='ТС';$label=(string)preg_replace('/^Транспорт(?: основной| дополнительный)?: /u','',$item);
                if (str_starts_with($item,'Транспорт дополнительный: ')) $label.=' (прицеп)';
            } elseif (str_starts_with($item,'Файл: ')) {
                $group='Файлы';$label=substr($item,strlen('Файл: '));
            } else {
                $group='ТС';$label=$item;
            }
            $groups[$group][]=preg_replace('/([\\\\`*_{}\[\]()#+.!>~|=\-^])/u','\\\\$1',$label)??$label;
        }
        $lines=[];
        foreach ($groups as $group=>$labels) if ($labels) $lines[]='**• '.$group.':** '.implode(', ',array_values(array_unique($labels)));
        return implode("\n",$lines);
    }
}
