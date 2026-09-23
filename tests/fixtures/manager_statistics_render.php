<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function base_path(string $path=''): string { global $root; return $root.($path!==''?'/'.ltrim($path,'/'):''); }
function app_url(string $path): string { return $path; }
function app_base_path(): string { return ''; }
function current_app_path(): string { return '/company/statistics/managers'; }
function csrfToken(): string { return str_repeat('a', 64); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$_SESSION = [
    'role_code'=>'logist', 'company_id'=>1, 'user_id'=>1,
    'user_name'=>'Анна Белова',
];

$_GET = ['mode'=>'week','metric'=>'trips'];
$company = ['name'=>'ООО «ПЛАНЭКС»'];
$dbError = null;
$eligibleManagers = [
    ['membership_id'=>101,'full_name'=>'Анна Белова'],
    ['membership_id'=>102,'full_name'=>'Борис Серов'],
    ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ['membership_id'=>104,'full_name'=>'Глеб Романов'],
];
$filters = [
    'mode'=>'week','metric'=>'trips','group'=>'week','from'=>'2026-08-03','to'=>'2026-09-23',
    'previous_from'=>'2026-06-08','previous_to'=>'2026-07-29','manager_ids'=>[],'compare'=>false,'duration_days'=>52,
];
$trend=[];$previousTrend=[];
foreach ([14,18,16,20,21,19,25,30] as $index=>$value) {
    $start=(new DateTimeImmutable('2026-08-03'))->modify('+'.$index.' weeks');$end=$start->modify('+6 days');
    if ($end > new DateTimeImmutable('2026-09-23')) $end=new DateTimeImmutable('2026-09-23');
    $trend[]=['key'=>$start->format('Y-m-d'),'label'=>'Нед. '.$start->format('W').' · '.$start->format('d.m'),'from'=>$start->format('Y-m-d'),'to'=>$end->format('Y-m-d'),'trips'=>$value,'requests'=>$value*3+($index%3),'weight'=>$value*4280.0];
    $previousStart=$start->modify('-12 weeks');$previousEnd=$previousStart->modify('+6 days');
    $previousTrend[]=['key'=>$previousStart->format('Y-m-d'),'label'=>'Нед. '.$previousStart->format('W').' · '.$previousStart->format('d.m'),'from'=>$previousStart->format('Y-m-d'),'to'=>$previousEnd->format('Y-m-d'),'trips'=>max(2,$value-4),'requests'=>max(4,$value*3-10),'weight'=>max(1000,($value-4)*4100.0)];
}
$managers = [
 ['membership_id'=>101,'responsible_user_id'=>1,'name'=>'Анна Белова','is_active'=>true,'trips'=>57,'requests'=>164,'weight'=>242500.0,'completed'=>49,'cancelled'=>2,'requests_per_trip'=>2.9,'weight_per_trip'=>4254.4,'previous'=>[]],
 ['membership_id'=>102,'responsible_user_id'=>2,'name'=>'Борис Серов','is_active'=>true,'trips'=>45,'requests'=>139,'weight'=>194300.0,'completed'=>36,'cancelled'=>3,'requests_per_trip'=>3.1,'weight_per_trip'=>4317.8,'previous'=>[]],
 ['membership_id'=>103,'responsible_user_id'=>3,'name'=>'Вера Тихая','is_active'=>true,'trips'=>39,'requests'=>112,'weight'=>171800.0,'completed'=>31,'cancelled'=>1,'requests_per_trip'=>2.9,'weight_per_trip'=>4405.1,'previous'=>[]],
 ['membership_id'=>104,'responsible_user_id'=>4,'name'=>'Глеб Романов','is_active'=>true,'trips'=>22,'requests'=>61,'weight'=>88900.0,'completed'=>17,'cancelled'=>4,'requests_per_trip'=>2.8,'weight_per_trip'=>4040.9,'previous'=>[]],
];
$details=[];
foreach ([['2026-09-23',101,'Анна Белова',742,'in_progress',4,7200],['2026-09-22',102,'Борис Серов',741,'completed',3,5100],['2026-09-22',103,'Вера Тихая',739,'completed',2,4600],['2026-09-21',104,'Глеб Романов',737,'cancelled',1,1500]] as $index=>$row) {
    $details[]=['id'=>$index+1,'actual_start_date'=>$row[0],'responsible_membership_id'=>$row[1],'responsible_name_snapshot'=>$row[2],'business_id'=>$row[3],'status'=>$row[4],'request_count'=>$row[5],'total_mass_kg'=>$row[6]];
}
$managerTrends=[];
$periodRows=$trend;
foreach($managers as$managerIndex=>$manager){
    $values=[];$share=[.35,.28,.24,.13][$managerIndex];
    foreach($trend as$periodIndex=>$period){
        $values[]=array_merge($period,['trips'=>(int)round($period['trips']*$share),'requests'=>(int)round($period['requests']*$share),'weight'=>$period['weight']*$share]);
    }
    $managerTrends[]=['membership_id'=>$manager['membership_id'],'name'=>$manager['name'],'values'=>$values];
    foreach($values as$periodIndex=>$value)$periodRows[$periodIndex]['manager_values'][$manager['membership_id']]=$value;
}
$statistics = [
 'totals'=>['trips'=>163,'requests'=>476,'weight'=>697500.0,'active_managers'=>4,'completed'=>133,'cancelled'=>10],
 'previous_totals'=>['trips'=>128,'requests'=>361,'weight'=>522200.0,'active_managers'=>4,'completed'=>109,'cancelled'=>8],
 'managers'=>$managers,'trend'=>$trend,'previous_trend'=>$previousTrend,'manager_trends'=>$managerTrends,
 'period_rows'=>$periodRows,'details'=>$details,'available_years'=>[2026,2025,2024],'scope'=>'all',
];
if (getenv('PLANEX_MANAGER_STATISTICS_EMPTY') === '1') {
    $statistics['totals'] = ['trips'=>0,'requests'=>0,'weight'=>0.0,'active_managers'=>0,'completed'=>0,'cancelled'=>0];
    $statistics['previous_totals'] = $statistics['totals'];
    $statistics['managers'] = [];
    $statistics['manager_trends'] = [];
    $statistics['period_rows'] = [];
    $statistics['details'] = [];
    $eligibleManagers = [];
}
$pageTitle = 'Работа логистов';
$pageContext = 'Статистика › Работа логистов › Компания: ООО «ПЛАНЭКС»';
$config = ['app'=>['app_name'=>'ERP PLANEX']];
$db = new stdClass();
$pageStylesheets = ['/assets/css/manager-statistics.css'];
ob_start();
require $root.'/app/View/pages/company_manager_statistics.php';
$content = (string)ob_get_clean();
require $root.'/app/View/layouts/main.php';
