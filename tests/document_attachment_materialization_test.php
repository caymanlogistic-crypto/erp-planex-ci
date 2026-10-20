<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Service/RouteExecutorReviewMaterializationService.php';

use App\Service\RouteExecutorReviewMaterializationService as Materializer;

$assert=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$file=static fn(string $field,string $name,int $index=0,string $form='driver'):array=>[
    'key'=>'lab-'.$form.'-form|'.$field,'index'=>$index,'name'=>$name,
    'mime'=>'application/pdf','tmp_name'=>'fixture-'.$name,'error'=>UPLOAD_ERR_OK,'size'=>strlen($name),
];
$stored=[
    $file('predef_doc[passport][]','first.pdf'),
    $file('predef_doc[driver_license][]','license.pdf'),
    $file('predef_doc[passport][]','second.pdf'),
    $file('predef_doc[passport][]','third.pdf',9),
    $file('predef_doc[company_card][]','company.pdf',0,'contractor'),
    $file('predef_doc[primary][sts][]','primary-one.pdf',0,'vehicle'),
    $file('predef_doc[secondary][sts][]','secondary.pdf',0,'vehicle'),
    $file('predef_doc[primary][sts][]','primary-two.pdf',0,'vehicle'),
    $file('custom_doc_file[7]','custom-fixed.pdf',12),
    $file('custom_doc_file[11][]','custom-one.pdf',17),
    $file('custom_doc_file[11][]','custom-two.pdf',17),
    $file('custom_doc_file[secondary][4][]','secondary-custom.pdf',0,'vehicle'),
];
$snapshot=['controls'=>[
    ['formId'=>'lab-driver-form','name'=>'custom_doc_type[7]','value'=>'Согласие','type'=>'text'],
    ['formId'=>'lab-driver-form','name'=>'custom_doc_type_new[11]','value'=>'Допуск','type'=>'text'],
    ['formId'=>'lab-vehicle-form','name'=>'custom_doc_type[secondary][4]','value'=>'Страховка','type'=>'text'],
]];
[$post,$files]=Materializer::standardInput($snapshot,$stored);
$assert(array_values($files['driver_predef_doc']['name']['passport'])===['first.pdf','second.pdf','third.pdf'],'Repeated historical control indices must preserve every passport in source order');
$assert($files['driver_predef_doc']['tmp_name']['passport']===['fixture-first.pdf','fixture-second.pdf','fixture-third.pdf'],'Parallel upload attributes must stay paired');
$assert($files['driver_predef_doc']['name']['driver_license']===['license.pdf'],'Independent document types stay independent');
$assert($files['contractor_predef_doc']['name']['company_card']===['company.pdf'],'Independent forms stay independent');
$assert($files['vehicle_predef_doc']['name']['primary']['sts']===['primary-one.pdf','primary-two.pdf'],'Primary files preserve all pages');
$assert($files['vehicle_predef_doc']['name']['secondary']['sts']===['secondary.pdf'],'Secondary files stay on their unit');
$assert($files['driver_custom_doc_file']['name'][7]==='custom-fixed.pdf' && $post['driver']['custom_doc_type'][7]==='Согласие','Fixed custom field index must retain its label');
$assert($files['driver_custom_doc_file']['name'][11]===['custom-one.pdf','custom-two.pdf'] && $post['driver']['custom_doc_type_new'][11]==='Допуск','Custom multi-file rows keep their label index');
$assert($files['vehicle_custom_doc_file']['name']['secondary'][4]===['secondary-custom.pdf'],'Vehicle custom row keeps unit and label index');
$sparse=['controls'=>[
    ['formId'=>'lab-driver-form','name'=>'custom_doc_type[]','value'=>'Пустая первая строка','type'=>'text'],
    ['formId'=>'lab-driver-form','name'=>'custom_doc_type[]','value'=>'Согласие второй строки','type'=>'text'],
    ['formId'=>'lab-vehicle-form','name'=>'custom_doc_type[primary][]','value'=>'Первый без файла','type'=>'text'],
    ['formId'=>'lab-vehicle-form','name'=>'custom_doc_type[primary][]','value'=>'Второй с файлом','type'=>'text'],
]];
[$sparsePost,$sparseFiles]=Materializer::standardInput($sparse,[
    $file('custom_doc_file[]','driver-second-row.pdf',1),
    $file('custom_doc_file[primary][]','vehicle-second-row.pdf',1,'vehicle'),
]);
$assert(!isset($sparseFiles['driver_custom_doc_file']['name'][0]) && $sparseFiles['driver_custom_doc_file']['name'][1]==='driver-second-row.pdf'
    && $sparsePost['driver']['custom_doc_type'][1]==='Согласие второй строки','Implicit custom rows must not shift a later upload onto an empty earlier label');
$assert(!isset($sparseFiles['vehicle_custom_doc_file']['name']['primary'][0]) && $sparseFiles['vehicle_custom_doc_file']['name']['primary'][1]==='vehicle-second-row.pdf'
    && $sparsePost['vehicle']['custom_doc_type']['primary'][1]==='Второй с файлом','Vehicle implicit custom rows retain sparse label/control indices');
foreach ([['custom_doc_file[]','driver'],['custom_doc_file[primary][]','vehicle']] as [$field,$form]) {
    try { Materializer::standardInput($sparse,[$file($field,'one.pdf',1,$form),$file($field,'two.pdf',1,$form)]); throw new RuntimeException('An implicit custom row accepted two scalar files under one label slot'); }
    catch (DomainException $expected) {}
}
foreach ([['custom_doc_file[7]','driver'],['predef_doc[company_card]','contractor']] as [$field,$form]) {
    try { Materializer::standardInput([],[$file($field,'one.pdf',0,$form),$file($field,'two.pdf',1,$form)]); throw new RuntimeException('A repeated scalar attachment silently replaced a file'); }
    catch (DomainException $expected) { $assert(str_contains($expected->getMessage(),'документ'),'Scalar collision is rejected explicitly'); }
}
echo "DOCUMENT_ATTACHMENT_MATERIALIZATION=PASS\n";
