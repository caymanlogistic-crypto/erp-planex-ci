<?php

declare(strict_types=1);
$main=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');
if(!str_contains($main,'data-closing-grid data-default-problems-only="1"')) throw new RuntimeException('default problem-only marker missing');
if(!str_contains($main,'data-closing-problem-only checked')) throw new RuntimeException('problem filter must be checked by default');
if(str_contains($main,'<span class="badge badge-success"><span class="dot"></span>Исключение</span>')) throw new RuntimeException('exempt type badge forbidden');
if(!str_contains($main,"if(!empty($row['is_exempt'])):?><span class=\"text-muted\">—</span>")) throw new RuntimeException('exempt type must render dash');
echo "closing_default_problem_filter_ui_contract: PASS\n";
