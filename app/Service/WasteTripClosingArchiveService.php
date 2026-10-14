<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use RuntimeException;
use ZipArchive;

/** Downloads the same visible trip selection as the closing summary, with its attached files. */
final class WasteTripClosingArchiveService
{
    /** @param list<array<string,mixed>> $trips */
    public static function create(int $companyId, array $trips): string
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('На сервере недоступно создание ZIP.');
        $path=tempnam(sys_get_temp_dir(),'trip-closing-');
        if ($path===false) throw new RuntimeException('Не удалось создать временный файл архива.');
        $report=null;
        $zip=new ZipArchive();
        try {
            $report=WasteTripClosingReportService::xlsx(WasteTripClosingReportService::rows($trips));
            if ($zip->open($path,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Не удалось открыть архив.');
            if (!$zip->addFile($report,'Сводный_отчёт.xlsx')) throw new RuntimeException('Не удалось добавить сводный отчёт.');
            $used=[];$missing=[];
            foreach ($trips as $trip) {
                $tripId=(int)$trip['id'];
                $number=self::segment((string)($trip['business_id']??$tripId),'№'.$tripId);
                $description=self::segment((string)($trip['comment']??''),'',60);
                $folder='Рейс_№'.$number.($description!==''?'_'.$description:'').'_ID'.$tripId.'/';
                if (!$zip->addEmptyDir(rtrim($folder,'/'))) throw new RuntimeException('Не удалось добавить папку рейса.');
                foreach ($trip['documents']??[] as $document) {
                    $relative=(string)($document['relative_path']??'');
                    $prefix='companies/'.$companyId.'/documents/waste_trip/'.$tripId.'/';
                    if (!str_starts_with($relative,$prefix)) throw new DomainException('Документ находится вне рейса.');
                    $root=realpath(storage_path('companies/'.$companyId.'/documents/waste_trip/'.$tripId));
                    $source=realpath(storage_path($relative));
                    if ($source!==false && ($root===false || !str_starts_with($source,$root.DIRECTORY_SEPARATOR))) {
                        throw new DomainException('Документ находится вне рейса.');
                    }
                    if ($root===false || $source===false || !is_file($source) || !is_readable($source)) {
                        $missing[]='Рейс №'.$number.': '.(string)($document['original_name']??'документ');
                        continue;
                    }
                    $slot=(string)($document['slot_code']??'');
                    $type=match($slot) {
                        'contract'=>match((string)($document['requirement_code']??'')) {'agreement'=>'Договор','application'=>'Заявка',default=>'Договор_или_заявка'},
                        'invoice'=>'Счёт', 'act'=>((string)($document['requirement_code']??'')==='upd'?'УПД':'Акт'),
                        'waybill'=>'Путевой_лист', 'extra'=>self::segment((string)($document['custom_title']??''),'Другой_документ',60),
                        default=>'Документ',
                    };
                    $original=str_replace('\\','/',(string)($document['original_name']??''));
                    $original=basename($original);
                    $extension=self::segment((string)pathinfo($original,PATHINFO_EXTENSION),'',12);
                    $base=self::segment((string)pathinfo($original,PATHINFO_FILENAME),'файл',90);
                    $stem=$folder.$type.'_'.$base;
                    $name=$stem.($extension!==''?'.'.$extension:'');
                    $suffix=2;
                    while (isset($used[$name])) $name=$stem.'_'.$suffix++.($extension!==''?'.'.$extension:'');
                    $used[$name]=true;
                    if (!$zip->addFile($source,$name)) {
                        $missing[]='Рейс №'.$number.': '.(string)($document['original_name']??'документ');
                    }
                }
            }
            if ($missing!==[] && !$zip->addFromString('Недоступные_файлы.txt',
                "Эти документы есть в реестре, но их файлы не удалось включить в архив:\n".implode("\n",$missing)."\n")) {
                throw new RuntimeException('Не удалось добавить список недоступных файлов.');
            }
            if (!$zip->close()) throw new RuntimeException('Не удалось завершить архив.');
            return $path;
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($path);
            throw $error;
        } finally {
            if ($report!==null) @unlink($report);
        }
    }

    private static function segment(string $value, string $fallback, int $limit=80): string
    {
        $value=preg_replace('/[\\x00-\\x1f\\x7f\\/\\\\:*?"<>|]+/u','_',trim($value))??'';
        $value=trim(preg_replace('/\\s+/u',' ',$value)??''," ._-");
        $value=$value!==''?$value:$fallback;
        if (preg_match_all('/./us',$value,$characters)===false) return substr($value,0,$limit);
        return implode('',array_slice($characters[0],0,$limit));
    }
}
