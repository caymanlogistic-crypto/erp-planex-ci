<?php
declare(strict_types=1);

namespace App\Service;

final class RouteExecutorReviewEmail
{
    public static function build(array $package, bool $partial): array
    {
        $heading=$partial?'Первичные данные исполнителя рейса':'Полный комплект данных исполнителя рейса';
        $lead=$partial?'Это первичные данные. Ожидайте полный комплект вторым письмом.':'Передан полный актуальный комплект данных исполнителя рейса.';
        $text=$heading."\n".$lead."\n\n";
        $rows='';
        foreach ($package['rows'] as $row) {
            $name=(string)$row['name'];$value=(string)$row['value'];
            $text.=$name.': '.$value."\n";
            $rows.='<tr><th style="text-align:left;padding:8px;border-bottom:1px solid #ded7ca">'.self::escape($name).'</th><td style="padding:8px;border-bottom:1px solid #ded7ca">'.self::escape($value).'</td></tr>';
        }
        $names=array_map(static fn(array $file):string=>(string)$file['name'],$package['attachments']);
        $text.="\nФайлы: ".($names?implode(', ',$names):'не приложены')."\n";
        $text.='Готово: '.implode(', ',$package['ready'])."\n";
        $html='<div style="font:14px Arial,sans-serif;color:#292622;background:#f5f2ec;padding:24px">'
            .'<div style="max-width:760px;margin:auto;background:#fff;border:1px solid #d8d0c2;padding:24px">'
            .'<h2 style="color:#70451f;margin-top:0">'.self::escape($heading).'</h2><p>'.self::escape($lead).'</p>'
            .'<p><b>Готово:</b> '.self::escape(implode(', ',$package['ready'])).'</p>'
            .'<table style="width:100%;border-collapse:collapse">'.$rows.'</table>'
            .'<p><b>Файлы:</b> '.self::escape($names?implode(', ',$names):'не приложены').'</p></div></div>';
        return ['text'=>$text,'html'=>$html];
    }

    private static function escape(string $value): string
    { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
