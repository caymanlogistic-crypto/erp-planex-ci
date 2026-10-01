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
        $rows='';
        foreach ($package['rows'] as $row) {
            $name=(string)$row['name'];$value=(string)$row['value'];
            $text.=$name.': '.$value."\n";
            $rows.='<tr><th style="text-align:left;padding:8px;border-bottom:1px solid #ded7ca">'.self::escape($name).'</th><td style="padding:8px;border-bottom:1px solid #ded7ca">'.self::escape($value).'</td></tr>';
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
            .'<table style="width:100%;border-collapse:collapse">'.$rows.'</table>'
            .'<p><b>Архив документов:</b> '.self::escape($names?implode(', ',$names):'не приложен').'</p></div></div>';
        return ['text'=>$text,'html'=>$html];
    }

    private static function escape(string $value): string
    { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
