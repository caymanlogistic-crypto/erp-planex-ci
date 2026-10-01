<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Service/RouteExecutorReviewPackage.php';
require_once __DIR__.'/../app/Service/RouteExecutorReviewEmail.php';
require_once __DIR__.'/../app/Service/RouteExecutorReviewArchive.php';
require_once __DIR__.'/../app/Service/MaxEventCatalog.php';

use App\Service\MaxEventCatalog;
use App\Service\RouteExecutorReviewEmail;
use App\Service\RouteExecutorReviewPackage;
use App\Service\RouteExecutorReviewArchive;

$assert=static function(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); };
$routeSource=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_route_executors.php');
foreach (['EmployeeMailProfileService.php','DocumentMailTransport.php','MaxEventCatalog.php',
    'MaxMessagingService.php','MaxEventNotificationService.php','RouteExecutorReviewRecipientService.php',
    'RouteExecutorReviewPackage.php','RouteExecutorReviewCardService.php','RouteExecutorReviewArchive.php','RouteExecutorReviewEmail.php'] as $dependency) {
    $assert(str_contains($routeSource,"require_once base_path('app/Service/{$dependency}')"),
        "Review route must load {$dependency} before handling a send");
}
$control=static fn(string $form,string $name,string $value):array=>['formId'=>'lab-'.$form.'-form','name'=>$name,'value'=>$value,'label'=>$name,'type'=>'text'];
$contract=[
    $control('contractor','name','ИП Иванов Иван Иванович'),$control('contractor','inn','123456789012'),
    $control('contractor','contractor_type','individual'),$control('contractor','ogrn','123456789012345'),
    $control('contractor','director_full_name','Иванов Иван Иванович'),$control('contractor','director_position','ИП'),
    $control('contractor','bank_account','12345678901234567890'),$control('contractor','bank_bik','123456789'),
    $control('contractor','bank_name','Банк'),$control('contractor','bank_corr_account','12345678901234567890'),
    $control('contractor','contacts[0][contact_person]','Анна'),$control('contractor','contacts[0][phone]','+7 900 000-00-00'),
    $control('contractor','contacts[0][email]','anna@example.test'),
];
$driver=[$control('driver','full_name','Петров Пётр Петрович'),$control('driver','snils','123-456-789 00')];
$package=RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,$driver)],[]);
$assert($package['ready']===['Обучение водителя'],'Training readiness should survive an incomplete contract');
$assert(!$package['full'],'Incomplete package cannot be final');
$assert(in_array('Файл: Паспорт водителя',$package['missing'],true),'Missing documents appear in MAX list');
$package=RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,[$control('contractor','legal_address','Москва, 1')],$driver)],[]);
$assert(in_array('Подписание договора',$package['ready'],true),'IP contract requires legal address but not KPP');
$legal=array_merge($contract,[$control('contractor','legal_address','Москва, 1'),$control('contractor','kpp','123456789')]);
foreach ($legal as &$item) if ($item['name']==='contractor_type') $item['value']='legal_entity';
unset($item);
$legal=array_values(array_filter($legal,static fn(array $item):bool=>$item['name']!=='director_full_name'));
$package=RouteExecutorReviewPackage::inspect(['controls'=>$legal],[]);
$assert(!in_array('Подписание договора',$package['ready'],true),'Legal entity requires director');
$mail=RouteExecutorReviewEmail::build(RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,[$control('contractor','legal_address','Москва, 1')],$driver)],[]),true,'#123 Вывоз отходов');
$assert(str_contains($mail['text'],'Ожидайте полный комплект вторым письмом'),'First email must explain the second package');
$assert(str_contains($mail['text'],'#123 Вывоз отходов') && str_contains($mail['html'],'<strong>Рейс: #123 Вывоз отходов</strong>'),'Email must prominently include the selected trip');
$extra=$control('driver','extra_phones[]','+7 900 000-00-00');$extra['label']='+7 900 000-00-00';
$extraPackage=RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,$driver,[$extra])],[]);
$extraMail=RouteExecutorReviewEmail::build($extraPackage,true,'#123 Вывоз отходов');
$assert(str_contains($extraMail['text'],"ВОДИТЕЛЬ:\n") && str_contains($extraMail['text'],'Доп контакты: +7 900 000-00-00'),'Additional phone is in the driver block');
$vehicles=[
    $control('vehicle','set_type','coupling'),
    $control('vehicle','units[primary][brand]','КамАЗ'),
    $control('vehicle','units[secondary][plate_number]','А123ВС77'),
];
$vehicleMail=RouteExecutorReviewEmail::build(RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,$driver,$vehicles)],[]),true,'#123 Вывоз отходов');
$assert(substr_count($vehicleMail['html'],'<table data-section=')===3,'Email has exactly three separate tables');
$assert(strpos($vehicleMail['html'],'data-section="contractor"')<strpos($vehicleMail['html'],'data-section="driver"')
    && strpos($vehicleMail['html'],'data-section="driver"')<strpos($vehicleMail['html'],'data-section="vehicle"'),'Email tables follow contractor, driver, vehicle order');
$assert(str_contains($vehicleMail['html'],'Тягач') && str_contains($vehicleMail['html'],'Полуприцеп / прицеп')
    && strpos($vehicleMail['html'],'КамАЗ')<strpos($vehicleMail['html'],'А123ВС77'),'Vehicle table separates tractor and trailer');
$assert(str_contains($vehicleMail['text'],"ПОДРЯДЧИК:\n") && str_contains($vehicleMail['text'],"ВОДИТЕЛЬ:\n")
    && str_contains($vehicleMail['text'],"ТРАНСПОРТНОЕ СРЕДСТВО:\n"),'Plain email has matching blocks');
$singleMail=RouteExecutorReviewEmail::build(RouteExecutorReviewPackage::inspect(['controls'=>array_merge($driver,[
    $control('vehicle','set_type','single'),$control('vehicle','units[primary][brand]','ГАЗ'),
])],[]),true,'#124 Одиночка');
$assert(str_contains($singleMail['html'],'Автомобиль') && !str_contains($singleMail['html'],'Полуприцеп / прицеп'),
    'Single vehicle must not show a trailer section');
$fullMail=RouteExecutorReviewEmail::build($extraPackage,false,'#123 Вывоз отходов');
$assert(str_contains($fullMail['html'],'background:#eaf6e9') && str_contains($fullMail['html'],'background:#f5fff4'),'Full email has light green background');
foreach (['route_executor.partial','route_executor.full'] as $event) {
    $text=MaxEventCatalog::render($event,MaxEventCatalog::event($event)['template'],'plain',MaxEventCatalog::example($event));
    $assert(str_contains($text,'**Рейс \#123 Перевозка отходов**'),'MAX events must include the trip in bold before contractor');
    $assert(strpos($text,'**Рейс')<strpos($text,'Подрядчик:'),'Trip must precede contractor');
    $assert(str_contains($text,'ДАННЫХ НЕХВАТАЕТ:'),'MAX must end with missing data section');
}
$old="Первичные данные исполнителя рейса\nПодрядчик: {{contractor}}";
$upgraded=MaxEventCatalog::currentTemplate('route_executor.partial',$old);
$assert(str_contains($upgraded,'**Рейс {{trip}}**') && str_ends_with($upgraded,"ДАННЫХ НЕХВАТАЕТ:\n{{missing}}"),'Saved MAX templates must be upgraded');
$zip=RouteExecutorReviewArchive::build([
    ['key'=>'lab-driver-form|predef_doc[passport][]','name'=>'123.pdf','bytes'=>'passport content'],
    ['key'=>'lab-driver-form|predef_doc[driver_license][]','name'=>'scan.jpg','bytes'=>'license content'],
], '#123 Вывоз отходов');
$assert($zip['mime']==='application/zip' && $zip['name']==='Документы исполнителя рейса #123.zip','Email has one ZIP attachment');
$path=tempnam(sys_get_temp_dir(),'route-review-test-');file_put_contents($path,$zip['bytes']);
$archive=new ZipArchive();$assert($archive->open($path)===true,'ZIP opens');
$assert($archive->getFromName('Паспорт водителя.pdf')==='passport content','Passport uses content label');
$assert($archive->getFromName('Водительское удостоверение.jpg')==='license content','License uses content label');
$archive->close();unlink($path);
echo "route_executor_review_workflow_test: PASS\n";
