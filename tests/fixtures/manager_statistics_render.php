<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function base_path(string $path=''): string { global $root; return $root.($path!==''?'/'.ltrim($path,'/'):''); }
function app_url(string $path): string { return $path; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$_GET = ['mode'=>'weeks','metric'=>'trips','compare'=>'1'];
$company = ['name'=>'ООО «ПЛАНЭКС»'];
$dbError = null;
$eligibleManagers = [
    ['membership_id'=>101,'full_name'=>'Анна Белова'],
    ['membership_id'=>102,'full_name'=>'Борис Серов'],
    ['membership_id'=>103,'full_name'=>'Вера Тихая'],
    ['membership_id'=>104,'full_name'=>'Глеб Романов'],
];
$filters = [
    'mode'=>'weeks','metric'=>'trips','group'=>'week','from'=>'2026-07-06','to'=>'2026-09-23',
    'previous_from'=>'2026-04-18','previous_to'=>'2026-07-05','manager_ids'=>[],'compare'=>true,'duration_days'=>80,
];
$trend=[];$previousTrend=[];
foreach ([7,11,9,14,12,16,13,18,15,21,19,23] as $index=>$value) {
    $trend[]=['key'=>'2026-W'.($index+28),'label'=>'Нед. '.($index+28),'trips'=>$value,'requests'=>$value*3+($index%3),'weight'=>$value*4280.0];
    $previousTrend[]=['key'=>'2026-W'.($index+16),'label'=>'Нед. '.($index+16),'trips'=>max(2,$value-4),'requests'=>max(4,$value*3-10),'weight'=>max(1000,($value-4)*4100.0)];
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
$statistics = [
 'totals'=>['trips'=>163,'requests'=>476,'weight'=>697500.0,'active_managers'=>4,'completed'=>133,'cancelled'=>10],
 'previous_totals'=>['trips'=>128,'requests'=>361,'weight'=>522200.0,'active_managers'=>4,'completed'=>109,'cancelled'=>8],
 'managers'=>$managers,'trend'=>$trend,'previous_trend'=>$previousTrend,'details'=>$details,'scope'=>'all',
];
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css">
<style>body{margin:0;background:var(--app-bg)}.fixture-topbar{height:46px;display:flex;align-items:center;padding:0 20px;border-bottom:1px solid var(--line);background:var(--surface-strong);font:700 11px var(--font-base);letter-spacing:.06em}.fixture-shell{padding:12px 8px 24px}</style></head><body>
<div class="fixture-topbar">ERP PLANEX · СТАТИСТИКА</div><main class="fixture-shell"><?php require $root.'/app/View/pages/company_manager_statistics.php'; ?></main>
</body></html>
