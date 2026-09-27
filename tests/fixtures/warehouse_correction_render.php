<?php

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
    'vehicle'=>'А 123 ВС','request_ids'=>[(string)(100 + $id)],'display_request_ids'=>[(string)(100 + $id)],'skipped_request_ids'=>[],'is_correction'=>false,'request_count'=>1,
    'mass_kg'=>1000,'stock_count'=>$warehouse === 1 ? 1 : 0,'stock_kg'=>$warehouse === 1 ? 1000 : 0,
    'actual_end_date'=>'2026-09-01','requests'=>[['id'=>(string)(100 + $id),'mass_kg'=>1000,'before_kg'=>0,'after_kg'=>1000]],
];
$skippedGroup = $group(21,2,'out','Второй рейс');
$skippedGroup['display_request_ids'] = ['121','999'];
$skippedGroup['skipped_request_ids'] = ['999'];
$skippedGroup['is_correction'] = true;
$data = [
    'warehouses'=>[1=>'Иркутск', 2=>'Краснодар'],
    'groups'=>[1=>[$group(20,1,'in','Первый рейс')],2=>[$skippedGroup]],
    'current'=>[1=>['request_count'=>1,'weight_kg'=>1000],2=>['request_count'=>0,'weight_kg'=>0]],
    'issues'=>[],'issue_count'=>0,
    'correction_flights'=>[['id'=>20,'name'=>'Первый рейс'],['id'=>21,'name'=>'Второй рейс']],
];
require base_path('app/View/pages/company_warehouse_stock.php');
