<?php

declare(strict_types=1);
$main=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');
if(!str_contains($main,'data-closing-mode data-closing-problem-only')) throw new RuntimeException('closing display mode select required');
if(!str_contains($main,'<option value="requires_fill" selected>Только требующие заполнения</option>')) throw new RuntimeException('requires-fill mode must be selected by default');
if(str_contains($main,'<span class="badge badge-success"><span class="dot"></span>Исключение</span>')) throw new RuntimeException('exempt type badge forbidden');
if(!str_contains($main,'if(!empty($row[\'is_exempt\'])):?><span class="text-muted">—</span>')) throw new RuntimeException('exempt type must render dash');
echo "closing_default_problem_filter_ui_contract: PASS\n";
