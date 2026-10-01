<?php
require_once __DIR__.'/../../app/Service/WarehouseStockService.php';

$_SESSION = ['role_code'=>'company_owner'];
$_GET = [];
$dbError = null;
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ui_date(string $value): string { return $value; }
function app_url(string $path): string { return $path; }
function base_path(string $path): string { return dirname(__DIR__, 2).'/'.$path; }
function csrfField(): string { return '<input type="hidden" name="_csrf_token" value="fixture-token">'; }
$group = static fn(int $id, int $warehouse, string $direction, string $name): array => [
    'key'=>$warehouse.':'.$id.':'.$direction,'flight_id'=>$id,'warehouse_id'=>$warehouse,
    'direction'=>$direction,'date'=>'2026-09-01','name'=>$name,'driver'=>'Водитель',
    'vehicle'=>'А 123 ВС','request_ids'=>[(string)(100 + $id)],'display_request_ids'=>[(string)(100 + $id)],'warehouse_only_request_ids'=>[],'trip_only_request_ids'=>[],'is_correction'=>false,'request_count'=>1,
    'mass_kg'=>1000,'stock_count'=>$warehouse === 1 ? 1 : 0,'stock_kg'=>$warehouse === 1 ? 1000 : 0,
    'actual_end_date'=>'2026-09-01','requests'=>[['id'=>(string)(100 + $id),'mass_kg'=>1000,'before_kg'=>0,'after_kg'=>1000]],
];
$skippedGroup = $group(21,2,'out','Второй рейс');
$skippedGroup['display_request_ids'] = ['121','999'];
$skippedGroup['warehouse_only_request_ids'] = ['121'];
$skippedGroup['trip_only_request_ids'] = ['999'];
$skippedGroup['is_correction'] = true;
$data = [
    'warehouses'=>[1=>'Иркутск', 2=>'Краснодар'],
    'groups'=>[1=>[$group(20,1,'in','Первый рейс')],2=>[$skippedGroup]],
    'current'=>[1=>['request_count'=>23,'weight_kg'=>19685,'request_ids'=>[120, 276052, 276550, 276575, 290129, 290168, 290174, 292026, 293577, 295145, 298396, 299844, 301123, 301572, 302980, 303129, 304182, 306250, 306258, 307690, 314572, 316979, 316991]],2=>['request_count'=>0,'weight_kg'=>0,'request_ids'=>[]]],
    'issues'=>[21=>['flight_id'=>21,'name'=>'Второй рейс','warehouse_id'=>2,'messages'=>['Краснодар / вывоз: на складе есть заявки вне рейса ERP: 121.', 'Новосибирск / списание: со склада списано 12 из 23 заявок рейса. Не списаны: 306474, 309026, 309808, 310330, 310865, 310875, 310884, 312762, 312821, 313029, 315058. Проверьте, были ли эти заявки на складе и почему нет списания.'],
        'reconcile_targets'=>['Краснодар / вывоз: на складе есть заявки вне рейса ERP: 121.'=>['warehouse_id'=>2,'direction'=>'out']],
        'has_movements'=>true,'movement_label'=>'Есть в журнале']],'issue_count'=>1,
    'correction_flights'=>[['id'=>20,'name'=>'Первый рейс'],['id'=>21,'name'=>'Второй рейс']],
    'exceptions'=>[['id'=>1,'flight_id'=>20,'message_hash'=>str_repeat('a',64),'message_text'=>'Ранее согласованное расхождение.',
        'trip_name'=>'Первый рейс','warehouse_name'=>'Иркутск','approved_by_user_id'=>7,'approved_at'=>'2026-10-01 10:00:00',
        'revoked_by_user_id'=>null,'revoked_at'=>null,'matches_current'=>false]],
];
require base_path('app/View/pages/company_warehouse_stock.php');
