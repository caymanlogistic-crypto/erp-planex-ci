<?php
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function app_url(string $s):string{return $s;}
$data=['total'=>2,'totals'=>['all'=>2,'started'=>1,'completed'=>1],'status'=>'all','page'=>1,'source_at'=>'2026-10-13 12:55:01','rows'=>[
 ['id'=>245,'status'=>'started','comment'=>'Рейс в пути','actual_start_date'=>'2026-10-13','actual_end_date'=>null,'driver'=>'Иванов Иван','vehicle'=>'КАМАЗ А123ВС77'],
 ['id'=>244,'status'=>'completed','comment'=>'Завершённый рейс','actual_start_date'=>'2026-09-01','actual_end_date'=>'2026-09-01','driver'=>'Петров Пётр','vehicle'=>'МАЗ В456ОР77'],
]];$error=null;
echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
foreach(['app.css','erp-ui.css','route-executor-review-workflow.css','route-executor-header.css','route-executor-list-v016.css','gps-coverage.css']as $file)echo '<link rel="stylesheet" href="/assets/css/'.$file.'">';
echo '</head><body><main style="max-width:1500px;margin:20px auto;padding:0 12px">';
require dirname(__DIR__,2).'/app/View/pages/company_gps_coverage.php';
echo '</main><script src="/assets/js/gps-coverage.js"></script><script src="/assets/js/gps-route-listbox.js"></script></body></html>';
