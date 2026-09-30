<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Service/RouteExecutorReviewPackage.php';
require_once __DIR__.'/../app/Service/RouteExecutorReviewEmail.php';
require_once __DIR__.'/../app/Service/MaxEventCatalog.php';

use App\Service\MaxEventCatalog;
use App\Service\RouteExecutorReviewEmail;
use App\Service\RouteExecutorReviewPackage;

$assert=static function(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); };
$routeSource=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_route_executors.php');
foreach (['EmployeeMailProfileService.php','DocumentMailTransport.php','MaxEventCatalog.php',
    'MaxMessagingService.php','MaxEventNotificationService.php','RouteExecutorReviewRecipientService.php',
    'RouteExecutorReviewPackage.php','RouteExecutorReviewEmail.php'] as $dependency) {
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
$package=RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,[$control('contractor','legal_address','Москва, 1')],$driver)],[]);
$assert(in_array('Подписание договора',$package['ready'],true),'IP contract requires legal address but not KPP');
$legal=array_merge($contract,[$control('contractor','legal_address','Москва, 1'),$control('contractor','kpp','123456789')]);
foreach ($legal as &$item) if ($item['name']==='contractor_type') $item['value']='legal_entity';
unset($item);
$legal=array_values(array_filter($legal,static fn(array $item):bool=>$item['name']!=='director_full_name'));
$package=RouteExecutorReviewPackage::inspect(['controls'=>$legal],[]);
$assert(!in_array('Подписание договора',$package['ready'],true),'Legal entity requires director');
$mail=RouteExecutorReviewEmail::build(RouteExecutorReviewPackage::inspect(['controls'=>array_merge($contract,[$control('contractor','legal_address','Москва, 1')],$driver)],[]),true);
$assert(str_contains($mail['text'],'Ожидайте полный комплект вторым письмом'),'First email must explain the second package');
$assert(!str_contains($mail['text'],'номер рейса'),'Review email must not claim a trip number');
foreach (['route_executor.partial','route_executor.full'] as $event) {
    $text=MaxEventCatalog::render($event,MaxEventCatalog::event($event)['template'],'plain',MaxEventCatalog::example($event));
    $assert(!str_contains($text,'Рейс #') && !str_contains($text,'№'),'MAX events must not require a trip number');
}
echo "route_executor_review_workflow_test: PASS\n";
