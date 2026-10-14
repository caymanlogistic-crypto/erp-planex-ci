<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/planex-closing-archive-'.bin2hex(random_bytes(6));
function storage_path(string $path=''): string { global $root; return $root.($path!==''?'/'.$path:''); }
require_once __DIR__.'/../app/Service/WasteTripClosingService.php';
require_once __DIR__.'/../app/Service/WasteTripClosingReportService.php';
require_once __DIR__.'/../app/Service/WasteTripClosingArchiveService.php';

function check(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
function removeTree(string $path): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path)?:[] as $name) if ($name!=='.'&&$name!=='..') removeTree($path.'/'.$name);
    rmdir($path);
}

$files=storage_path('companies/25/documents/waste_trip/7');
mkdir($files,0700,true);
file_put_contents($files.'/first.pdf','first');
file_put_contents($files.'/second.pdf','second');
$trip=['id'=>7,'business_id'=>322,'comment'=>'Москва / Тверь','status'=>'completed',
    'documents'=>[
        ['relative_path'=>'companies/25/documents/waste_trip/7/first.pdf','original_name'=>'scan.pdf','slot_code'=>'contract','requirement_code'=>'agreement'],
        ['relative_path'=>'companies/25/documents/waste_trip/7/second.pdf','original_name'=>'scan.pdf','slot_code'=>'contract','requirement_code'=>'agreement'],
    ]];
$path=null;
try {
    $path=\App\Service\WasteTripClosingArchiveService::create(25,[$trip]);
    $zip=new ZipArchive();check($zip->open($path)===true,'archive opens');
    $names=[];for($i=0;$i<$zip->numFiles;$i++)$names[]=$zip->getNameIndex($i);
    check(in_array('Сводный_отчёт.xlsx',$names,true),'summary is at archive root');
    check(count(array_filter($names,static fn(string $name):bool=>str_contains($name,'Договор_scan')))==2,'duplicate names are unique');
    check(str_contains(implode(' ',$names),'Рейс_№322_Москва _ Тверь_ID7/'),'trip folder is descriptive');
    check($zip->getFromName('Рейс_№322_Москва _ Тверь_ID7/Договор_scan.pdf')==='first','first document content');
    $zip->close();
    $trip['documents'][]=['relative_path'=>'companies/25/documents/waste_trip/7/missing.pdf','original_name'=>'lost.pdf','slot_code'=>'invoice','requirement_code'=>'invoice'];
    $partial=\App\Service\WasteTripClosingArchiveService::create(25,[$trip]);
    $zip=new ZipArchive();check($zip->open($partial)===true,'partial archive opens');
    check(str_contains((string)$zip->getFromName('Недоступные_файлы.txt'),'Рейс №322: lost.pdf'),'missing file is clearly listed');
    check($zip->getFromName('Рейс_№322_Москва _ Тверь_ID7/Договор_scan.pdf')==='first','available document remains in partial archive');
    $zip->close();@unlink($partial);
    $emptyTrip=['id'=>8,'business_id'=>323,'comment'=>'Без документов','status'=>'completed','documents'=>[]];
    $emptyArchive=\App\Service\WasteTripClosingArchiveService::create(25,[$emptyTrip]);
    $zip=new ZipArchive();check($zip->open($emptyArchive)===true,'empty-document archive opens');
    check($zip->locateName('Сводный_отчёт.xlsx')!==false,'empty-document archive contains summary');
    check($zip->locateName('Рейс_№323_Без документов_ID8/')!==false,'empty-document archive contains trip folder');
    $zip->close();@unlink($emptyArchive);
    $many=[];
    for($id=10;$id<110;$id++){
        $directory=storage_path('companies/25/documents/waste_trip/'.$id);
        mkdir($directory,0700,true);
        file_put_contents($directory.'/act.pdf','act-'.$id);
        $many[]=['id'=>$id,'business_id'=>$id+300,'comment'=>'Рейс '.$id,'status'=>'completed',
            'documents'=>[['relative_path'=>'companies/25/documents/waste_trip/'.$id.'/act.pdf',
                'original_name'=>'подписанный акт.pdf','slot_code'=>'act','requirement_code'=>'act']]];
    }
    $bulk=\App\Service\WasteTripClosingArchiveService::create(25,$many);
    $zip=new ZipArchive();check($zip->open($bulk)===true,'100-trip archive opens');
    check($zip->numFiles===201,'100 folders and 100 documents plus summary');
    check($zip->getFromName('Рейс_№409_Рейс 109_ID109/Акт_подписанный акт.pdf')==='act-109','last trip is present');
    $zip->close();@unlink($bulk);
    $trip['documents'][0]['relative_path']='companies/26/documents/waste_trip/7/first.pdf';
    try {\App\Service\WasteTripClosingArchiveService::create(25,[$trip]);throw new RuntimeException('cross-company path accepted');}
    catch (DomainException $expected) {}
    echo "waste_trip_closing_archive_test: PASS\n";
} finally {
    if($path!==null)@unlink($path);
    removeTree($root);
}
