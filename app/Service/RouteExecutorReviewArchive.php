<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use ZipArchive;

final class RouteExecutorReviewArchive
{
    public static function build(array $attachments,string $trip): array
    {
        if (!class_exists(ZipArchive::class)) throw new DomainException('Для отправки архива недоступно расширение ZIP.');
        $path=tempnam(sys_get_temp_dir(),'route-executor-');
        if ($path===false) throw new DomainException('Не удалось подготовить архив.');
        $zip=new ZipArchive();
        try {
            if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new DomainException('Не удалось создать архив.');
            $names=self::names($attachments);
            foreach ($attachments as $index=>$attachment) {
                $name=$names[$index];
                if (!$zip->addFromString($name,(string)$attachment['bytes'])) throw new DomainException('Не удалось добавить файл в архив.');
            }
            if (!$zip->close()) throw new DomainException('Не удалось завершить архив.');
            $bytes=file_get_contents($path);
            if ($bytes===false) throw new DomainException('Не удалось прочитать архив.');
            $number=preg_match('/#([0-9]+)/',$trip,$match)?' #'.$match[1]:'';
            return ['name'=>'Документы исполнителя рейса'.$number.'.zip','bytes'=>$bytes,'mime'=>'application/zip'];
        } finally {
            if (is_file($path)) @unlink($path);
        }
    }

    public static function names(array $attachments): array
    {
        $used=[];$names=[];
        foreach ($attachments as $attachment) {
            $base=self::label((string)($attachment['key']??''),(string)$attachment['name']);
            $extension=strtolower(pathinfo((string)$attachment['name'],PATHINFO_EXTENSION));
            $name=$base.'.'.$extension;
            for ($index=2;isset($used[mb_strtolower($name)]);$index++) $name=$base.' '.$index.'.'.$extension;
            $used[mb_strtolower($name)]=true;
            $names[]=$name;
        }
        return $names;
    }

    private static function label(string $key,string $original): string
    {
        return match (true) {
            str_contains($key,'[passport]')=>'Паспорт водителя',
            str_contains($key,'[driver_license]')=>'Водительское удостоверение',
            str_contains($key,'[snils]')=>'СНИЛС водителя',
            str_contains($key,'[sts]') || str_contains($key,'[registration_certificate]')=>'СТС'.(str_contains($key,'[secondary]')?' прицепа':' автомобиля'),
            str_contains($key,'[diagnostic_card]')=>'Диагностическая карта'.(str_contains($key,'[secondary]')?' прицепа':' автомобиля'),
            str_contains($key,'[photo]')=>'Фото'.(str_contains($key,'[secondary]')?' прицепа':' автомобиля'),
            str_contains($key,'[inn_cert]')=>'Свидетельство ИНН',
            str_contains($key,'[ogrn_cert]')=>'Свидетельство ОГРН',
            str_contains($key,'[contract]')=>'Договор',
            str_contains($key,'[company_card]')=>'Карточка предприятия',
            default=>mb_substr(trim((string)preg_replace('/[\\\\\/\x00-\x1f:<>"|?*]+/u',' ',pathinfo(basename($original),PATHINFO_FILENAME)))?:'Документ',0,100),
        };
    }
}
