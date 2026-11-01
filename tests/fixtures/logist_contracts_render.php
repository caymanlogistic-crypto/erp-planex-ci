<?php
declare(strict_types=1);
$config = require dirname(__DIR__, 2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$owner = ($argv[1] ?? '') === 'owner';
$_SERVER['REQUEST_URI'] = $owner ? '/company/document-generator/logist-contracts' : '/company/logistics/contracts';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SESSION = ['role_code'=>$owner ? 'company_owner' : 'logist','company_id'=>7,'user_id'=>11,'account_id'=>3,'user_name'=>'Карина Г.','membership_id'=>11];
App\Security\AccessRuntime::setCurrent($owner
    ? App\Security\EffectiveAccess::systemOwner(11,7,array_column(App\Security\PageRegistry::companyPages(),'key'))
    : App\Security\EffectiveAccess::member(11,7,['company.logistics.contracts'=>true],[], 'logist'));
$company = ['id'=>7,'name'=>'ООО «ПЛАНЭКС»','status'=>'active'];
$db = new class([]) extends App\Core\Database { public function connection(): PDO { throw new RuntimeException('Fixture has no database'); } };
$pageTitle='Договоры логистов'; $pageContext=$owner?'Генератор договоров':'Логисты';
$pageStylesheets=['/assets/css/logist-contracts.css'];
$pageScripts=['/assets/js/registry-components.js','/assets/js/logist-contracts.js'];
$formError = null;
$csrfToken = csrfToken();
$rows = [[
    'id'=>2,'trip_id'=>1,'business_id'=>463,'contract_type'=>'lease','version_no'=>2,
    'status'=>'pending','review_reason'=>null,'logist_name'=>'Карина Г.',
    'snapshot'=>[
        'trip'=>['comment'=>'Вывоз отходов: Кемерово — Воскресенск'],
        'executor'=>['name'=>'ООО «СибТранс»'],
        'driver'=>['full_name'=>'Орлов Алексей Петрович'],
        'parameters'=>['start_date'=>'2026-11-03','finish_date'=>'2026-11-10','price'=>'185000','vat_mode'=>'Без НДС'],
        'vehicle'=>['model'=>'А123ВС77','plate'=>'А123ВС77'],
        'requests'=>[['zayavka_id'=>'312015'],['zayavka_id'=>'310867']],
    ],
], [
    'id'=>1,'trip_id'=>2,'business_id'=>459,'contract_type'=>'lease','version_no'=>1,
    'status'=>'approved','review_reason'=>null,'logist_name'=>'Карина Г.',
    'snapshot'=>[
        'trip'=>['comment'=>'Сборный рейс: Томск — Новосибирск'],
        'executor'=>['name'=>'ИП Кузнецов'],
        'driver'=>['full_name'=>'Кузнецов Павел Андреевич'],
        'parameters'=>['start_date'=>'2026-10-29','finish_date'=>'2026-11-05','price'=>'92000','vat_mode'=>'Без НДС'],
    ],
]];
if (($argv[2] ?? '') === 'many') {
    for ($i=3; $i<=55; $i++) {
        $row=$rows[1]; $row['id']=$i; $row['business_id']=500+$i; $row['snapshot']['trip']['comment']='Сборный рейс '.$i;
        $rows[]=$row;
    }
}
ob_start(); require BASE_PATH.'/app/View/pages/company_logist_contracts.php'; $content=ob_get_clean();
require BASE_PATH.'/app/View/layouts/main.php';
