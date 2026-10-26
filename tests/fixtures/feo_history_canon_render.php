<?php
declare(strict_types=1);
$config = require dirname(__DIR__, 2) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
$kind = $argv[1] ?? 'transfers';
$state = $argv[2] ?? 'populated';
if (!in_array($kind, ['transfers', 'weights'], true)) throw new InvalidArgumentException('Unknown history fixture');
$_SERVER['REQUEST_URI'] = '/company/feo/requests/' . $kind;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SESSION = ['role_code'=>'company_owner','company_id'=>7,'user_id'=>11,'account_id'=>3,'user_name'=>'Тестовый пользователь','membership_id'=>11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11,7,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$company = ['id'=>7,'name'=>'ООО «ПЛАНЭКС»','status'=>'active'];
$db = new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Fixture has no database'); } };
$page = 1; $dbError = null; $isOwner = true; $keyConfigured = true; $coordinatesOnly = false;
$pageTitle = 'Перемещение заявок';
$pageContext = 'Заявки ФЭО › ' . ($kind === 'transfers' ? 'Перемещение заявок' : 'Изменение веса заявок');
$pageStylesheets = ['/assets/css/registry-components.css'];
$pageScripts = ['/assets/js/registry-components.js'];
$batches = [
 ['id'=>18,'name'=>'Перемещение на склад','destination_json'=>json_encode(['address'=>'Москва, улица Примерная, дом 12, корпус 1','quality'=>'Только улица, дом не определён']),'created_at'=>'2026-10-18 15:30:00','request_count'=>12,'created_by_name'=>'Владимир С.','status'=>'applied'],
 ['id'=>17,'name'=>'Корректировка адреса для клиента','destination_json'=>json_encode(['address'=>'Санкт-Петербург, Невский проспект, 10','quality'=>'Точный адрес']),'created_at'=>'2026-10-17 13:20:00','request_count'=>2,'created_by_name'=>'Антон Ш.','status'=>'reverted'],
];
if ($kind==='transfers') {
 $specs=[
  [118,'На новый склад в Новом Уренгое','Россия, Ямало-Ненецкий автономный округ, Новый Уренгой, Таёжная улица, 177','22.10.2026 11:35','Антон Ш.','applied',12,320648,320000,3000,6737,7015,'329001'],
  [117,'Перенос погрузки на временную площадку по заявкам Северного направления','ЯНАО, Надымский район, рабочий посёлок Пангоды, улица Ленина, 26','21.10.2026 16:04','Владимир С.','applied',8,319450,319000,1000,2735,2850,null],
  [116,'Корректировка адреса 21.10','Ханты-Мансийский автономный округ, Сургут, Промышленная улица, 4','21.10.2026 12:10','Антон Ш.','reverted',36,317812,316000,3000,42746,43980,'328704'],
  [115,'Перемещение на базу подрядчика','Тюменская область, Тобольск, территория Промкомзона, 15','20.10.2026 18:44','Елена К.','applied',1,312158,312000,2000,133,145,null],
  [114,'Рейс на склад переработки','Красноярский край, Норильск, Вокзальная улица, 3','19.10.2026 09:20','Владимир С.','applied',72,309521,309000,3000,45402,46875,'327110'],
  [113,'Возврат после уточнения адреса','Республика Коми, Усинск, улица Нефтяников, 50','18.10.2026 13:03','Антон Ш.','reverted',5,308323,307000,2000,8520,8780,null],
 ];
 $batches=[];$batchIndexItems=[];
 foreach ($specs as [$id,$name,$address,$date,$author,$status,$count,$first,$base,$span,$net,$gross,$source]) {
  $ids=[(string)$first];$seen=array_fill_keys($ids,true);$seed=$first;
  while(count($ids)<$count){$seed=($seed*1664525+1013904223)&0xffffffff;$next=(string)($base+$seed%$span);if(!isset($seen[$next])){$ids[]=$next;$seen[$next]=true;}}
  $batches[]=['id'=>$id,'name'=>$name,'mode'=>$source?'request':'address','source_request_id'=>$source,'destination_json'=>json_encode(['address'=>$address]),'created_at'=>date('Y-m-d H:i:s',strtotime($date)),'request_count'=>$count,'created_by_name'=>$author,'status'=>$status];
  $batchIndexItems[$id]=['ids'=>$ids,'net_kg'=>$net,'gross_kg'=>$gross];
 }
 if($state==='scroll') for($i=0;$i<40;$i++){
  $copy=$batches[0];$copy['id']=200+$i;$copy['name']='Дополнительный блок '.$i;$batches[]=$copy;
  $batchIndexItems[$copy['id']]=$batchIndexItems[118];
 }
} else {
 $batchIndexItems=[];
 foreach ($batches as $batch) {
  $id=(int)$batch['id'];
  $batchIndexItems[$id]=['ids'=>$id===18?array_map('strval',range(100001,100012)):['100002','100003'],'before_net_kg'=>$id===18?null:1250,'after_net_kg'=>0,'before_gross_kg'=>1500,'after_gross_kg'=>2400];
 }
 for ($i=0;$i<70;$i++) {
  $id=100+$i;
  $batches[]=['id'=>$id,'name'=>'Проверочный блок '.$i,'created_at'=>'2026-10-16 11:30:00','request_count'=>2,'created_by_name'=>'Иван К.','status'=>$i%8===0?'reverted':'applied'];
  $batchIndexItems[$id]=['ids'=>[(string)(300000+$i),(string)(310000+$i)],'before_net_kg'=>1250+$i,'after_net_kg'=>1500+$i,'before_gross_kg'=>1700+$i,'after_gross_kg'=>1900+$i];
 }
}
if ($state==='empty') $batches=[];
ob_start();
require BASE_PATH . '/app/View/pages/' . ($kind==='transfers'?'company_feo_transfers.php':'company_feo_weight_batches.php');
$content = (string)ob_get_clean();
require BASE_PATH . '/app/View/layouts/main.php';
