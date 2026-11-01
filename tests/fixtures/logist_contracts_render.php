<?php
declare(strict_types=1);
function base_path(string $path = ''): string { return dirname(__DIR__, 2) . '/' . ltrim($path, '/'); }
function app_url(string $path = ''): string { return $path; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfToken(): string { return 'test-token'; }
$owner = ($argv[1] ?? '') === 'owner';
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
ob_start(); require base_path('app/View/pages/company_logist_contracts.php'); $content=ob_get_clean();
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/erp-ui.css"><link rel="stylesheet" href="/assets/css/registry-components.css"><link rel="stylesheet" href="/assets/css/logist-contracts.css"></head><body><main style="margin:24px auto;width:calc(100% - 48px)"><?= $content ?></main><script src="/assets/js/logist-contracts.js"></script></body></html>
