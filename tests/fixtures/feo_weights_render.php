<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function base_path(string $path=''): string { global $root; return $root.($path!=='' ? '/'.ltrim(str_replace('\\','/',$path),'/') : ''); }
function app_url(string $path): string { return $path; }
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfField(): string { return '<input type="hidden" name="_csrf_token" value="fixture-csrf">'; }
$page = 1; $dbError = null;
$batches = [['id'=>7,'name'=>'По просьбе клиента','comment'=>'Проверка','request_count'=>2,'created_by_name'=>'Менеджер','created_at'=>'2026-10-14 12:00:00','status'=>'applied']];
$batchIndexItems = [7=>['ids'=>['100001','100002'],'before_net_kg'=>1250,'after_net_kg'=>1300,'before_gross_kg'=>1500,'after_gross_kg'=>0]];
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"></head><body><main style="padding:24px;max-width:1690px;margin:auto;min-width:0"><?php require $root.'/app/View/pages/company_feo_weight_batches.php'; ?></main></body></html>
