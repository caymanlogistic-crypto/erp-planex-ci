<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Service/WasteTripClosingArchiveService.php';

$method=new ReflectionMethod(\App\Service\WasteTripClosingArchiveService::class,'segment');
$name=$method->invoke(null,'Москва / Тверь','',60);
if ($name!=='Москва _ Тверь') throw new RuntimeException('Cyrillic folder name was damaged.');
$long=str_repeat('Р',100);
$short=$method->invoke(null,$long,'',60);
if (preg_match_all('/./u',$short)!==60 || preg_match('//u',$short)!==1) {
    throw new RuntimeException('Long Cyrillic name was truncated incorrectly.');
}
echo "waste_trip_closing_archive_filename_test: PASS\n";
