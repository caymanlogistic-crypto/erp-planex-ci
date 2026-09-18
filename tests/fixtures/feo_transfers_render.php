<?php
// Synthetic UI fixture; no application authentication or database is replaced in runtime.
declare(strict_types=1);
$root=dirname(__DIR__,2);
function app_url(string $path): string { return $path; }
function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrfField(): string { return '<input type="hidden" name="_csrf_token" value="fixture-csrf">'; }
$coordinatesOnly=($argv[1]??'')==='coordinates';
$pageTitle=$coordinatesOnly?'Определение координат':'Перенос заявок';
$dbError=null; $isOwner=true; $keyConfigured=true; $page=1;
$batches=[['id'=>18,'name'=>'Перенос на склад','destination_json'=>json_encode(['address'=>'Москва, улица Примерная, дом 12, корпус 1','precision'=>'street','quality'=>'Только улица, дом не определён']),'created_at'=>'2026-09-18 15:30','request_count'=>12,'created_by_name'=>'Владимир С.','status'=>'applied']];
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"></head><body><main style="padding:24px;max-width:1690px;margin:auto;min-width:0"><?php require $root.'/app/View/pages/company_feo_transfers.php'; ?></main></body></html>
