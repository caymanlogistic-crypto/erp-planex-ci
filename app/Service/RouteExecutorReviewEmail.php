<?php
declare(strict_types=1);

namespace App\Service;

final class RouteExecutorReviewEmail
{
    public static function build(array $package, bool $partial, string $trip): array
    {
        $heading=$partial?'Первичные данные исполнителя рейса':'Полный комплект данных исполнителя рейса';
        $lead=$partial?'Это первичные данные. Ожидайте полный комплект вторым письмом.':'Передан полный актуальный комплект данных исполнителя рейса.';
        $text=$heading."\nРейс: ".$trip."\n".$lead."\n\n";
        $groups=['contractor'=>[],'driver'=>[],'vehicle'=>[]];
        $vehicleType='';
        foreach ($package['rows'] as $row) {
            $form=(string)($row['form']??'');
            $group=match ($form) {
                'lab-contractor-form'=>'contractor','lab-driver-form'=>'driver','lab-vehicle-form'=>'vehicle',default=>null,
            };
            if ($group===null) continue;
            $groups[$group][]=$row;
            if ($group==='vehicle' && ($row['field']??'')==='set_type') $vehicleType=(string)($row['raw_value']??'');
        }
        $tables='';
        foreach (['contractor'=>'ПОДРЯДЧИК','driver'=>'ВОДИТЕЛЬ','vehicle'=>'ТРАНСПОРТНОЕ СРЕДСТВО'] as $key=>$title) {
            $text.=$title.":\n";
            $rows='';
            if ($key==='vehicle') {
                $sections=['general'=>[],'primary'=>[],'secondary'=>[]];
                foreach ($groups[$key] as $row) {
                    $field=(string)($row['field']??'');
                    $role=preg_match('/^units\[(primary|secondary)\]/D',$field,$match)===1?$match[1]:'general';
                    $sections[$role][]=$row;
                }
                foreach ($sections as $role=>$items) {
                    if ($role==='secondary' && $vehicleType==='single') continue;
                    if ($role!=='general') {
                        $label=$role==='primary'?($vehicleType==='single'?'Автомобиль':'Тягач'):'Полуприцеп / прицеп';
                        $text.="\n".$label.":\n";
                        $rows.='<tr><th colspan="2" style="text-align:left;padding:9px;background:#f3efe8">'.self::escape($label).'</th></tr>';
                    }
                    [$partText,$partRows]=self::renderRows($items);
                    $text.=$partText;$rows.=$partRows;
                }
            } else {
                [$partText,$rows]=self::renderRows($groups[$key]);
                $text.=$partText;
            }
            $tables.='<table data-section="'.$key.'" style="width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #ded7ca">'
                .'<thead><tr><th colspan="2" style="text-align:left;padding:11px;background:#ece5d8;color:#473725">'.self::escape($title).'</th></tr></thead>'
                .'<tbody>'.$rows.'</tbody></table>';
            $text.="\n";
        }
        $names=RouteExecutorReviewArchive::names($package['attachments']);
        $text.="\nАрхив документов: ".($names?implode(', ',$names):'не приложен')."\n";
        $text.='Готово: '.implode(', ',$package['ready'])."\n";
        $background=$partial?'#f5f2ec':'#eaf6e9';
        $cardBackground=$partial?'#fff':'#f5fff4';
        $border=$partial?'#d8d0c2':'#b7d8b4';
        $headingColor=$partial?'#70451f':'#216a35';
        $html='<div style="font:14px Arial,sans-serif;color:#292622;background:'.$background.';padding:24px">'
            .'<div style="max-width:760px;margin:auto;background:'.$cardBackground.';border:1px solid '.$border.';padding:24px">'
            .'<h2 style="color:'.$headingColor.';margin-top:0">'.self::escape($heading).'</h2><p><strong>Рейс: '.self::escape($trip).'</strong></p><p>'.self::escape($lead).'</p>'
            .'<p><b>Готово:</b> '.self::escape(implode(', ',$package['ready'])).'</p>'
            .$tables
            .'<p><b>Архив документов:</b> '.self::escape($names?implode(', ',$names):'не приложен').'</p></div></div>';
        return ['text'=>$text,'html'=>$html];
    }

    private static function escape(string $value): string
    { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

    private static function renderRows(array $items): array
    {
        if (!$items) return ["Не заполнено\n",'<tr><td colspan="2" style="padding:8px;color:#7d756a">Не заполнено</td></tr>'];
        $text='';$html='';
        foreach ($items as $item) {
            $label=(string)($item['label']??$item['name']??'');$value=(string)($item['value']??'');
            $text.=$label.': '.$value."\n";
            $html.='<tr><th style="text-align:left;padding:8px;border-bottom:1px solid #ded7ca;width:42%">'.self::escape($label).'</th>'
                .'<td style="padding:8px;border-bottom:1px solid #ded7ca">'.self::escape($value).'</td></tr>';
        }
        return [$text,$html];
    }
}
