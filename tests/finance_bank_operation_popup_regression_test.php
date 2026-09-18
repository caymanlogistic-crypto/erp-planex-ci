<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/Service/FinanceMatchingRuleService.php';
use App\Service\FinanceMatchingRuleService as S;
function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException('FAIL: '.$message);}

$root=dirname(__DIR__);
$manual=file_get_contents($root.'/app/Service/FinanceMatchingRuleManualTrait.php');
$submit=file_get_contents($root.'/app/Http/Controllers/Company/BankFinanceActions/classifySubmit.php');
$classifyForm=file_get_contents($root.'/app/Http/Controllers/Company/BankFinanceActions/classifyForm.php');
$popup=file_get_contents($root.'/app/View/partials/company_bank_transaction_classify_form.php');
$employee=file_get_contents($root.'/app/Service/FinanceEmployeeBankSettlementService.php');
$batch=file_get_contents($root.'/app/Service/FinanceMatchingRuleBatchTrait.php');
$filters=file_get_contents($root.'/app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php');
$rulesForm=file_get_contents($root.'/app/View/partials/company_finance_matching_rule_form.php');

ok(str_contains($manual,"\$mode!=='manual'"),'manual executor rejects non-manual mode');
ok(!str_contains($manual,'self::createRule('),'manual executor cannot create matching rule');
ok(str_contains($manual,"classification_status='MANUAL'")&&str_contains($manual,'classification_locked=1'),'manual categorize persists MANUAL lock');
ok(str_contains($manual,"'rule_id'=>null")&&str_contains($manual,"'status'=>'MANUAL'"),'manual result is explicitly rule-less');
ok(str_contains($submit,'FinanceMatchingRuleService::createRule'),'popup rule mode creates canonical matching rule');
ok(str_contains($submit,'FinanceMatchingRuleService::applyAutoMatchToOperation'),'new popup rule is executed by canonical engine');
ok(str_contains($submit,"if(\$mode==='rule')"),'controller has explicit rule branch');
ok(str_contains($submit,'manualClassifyBankTransaction'),'controller has explicit manual branch');
ok(str_contains($submit,'resolveActiveEmployee'),'employee resolution is shared with rules screen');
ok(str_contains($manual,"\$action==='employee_cash_settlement'"),'manual action exposes employee settlement');
ok(str_contains($manual,'FinanceEmployeeBankSettlementService::settleBankTransaction'),'manual employee action delegates to shared bank settlement service');
ok(str_contains($employee,'$isManual = $ruleId === null'),'employee service derives manual mode from absent rule id');
ok(str_contains($employee,"\$classificationStatus = \$isManual ? 'MANUAL' : 'AUTO'"),'employee settlement distinguishes manual and auto source');
ok(str_contains($employee,'$classificationLocked = $isManual ? 1 : 0'),'manual employee settlement is locked');
ok(str_contains($employee,"'BANK'"),'employee movement source remains BANK');
ok(str_contains($employee,"\$movementType = \$originalType === 'EXPENSE' ? 'PAYMENT' : 'RETURN'"),'bank debit means payment to employee and bank credit means return');
ok(!str_contains($employee,'INSERT INTO finance_cash_resolutions'),'BANK <-> EMPLOYEE does not write CASH resolutions');
ok(str_contains($batch,'COALESCE(bt.classification_locked, 0) = 0'),'batch selection excludes locked bank transactions');
ok(str_contains($batch,"UPPER(COALESCE(fo.classification_status, 'UNALLOCATED')) <> 'MANUAL'"),'batch selection excludes MANUAL finance operations');
foreach(['action_type','target_employee_ref','target_cash_flow_center_id','target_dds_category_id','direction','counterparty_inn','purpose_contains','invoice_number_pattern','purpose_regex','bank_account_id','amount_from','amount_to','priority'] as $field){ok(str_contains($popup,'name="'.$field.'"'),'popup exposes rule field '.$field);}
ok(str_contains($popup,'value="categorize"')&&str_contains($popup,'value="employee_cash_settlement"'),'popup exposes same active rule types as rules screen');
ok(str_contains($rulesForm,'employee_cash_settlement')&&str_contains($rulesForm,'categorize'),'separate rules screen remains intact');
ok(str_contains($popup,'tx-rule-settings')&&str_contains($popup,'distribution_mode'),'rule-only conditions are gated by selected mode');
ok(str_contains($classifyForm,'classification_rule_id')&&str_contains($classifyForm,'FinanceMatchingRuleService::findRule'),'operation details fetch the persisted matching rule read-only');
ok(str_contains($popup,'Сработало правило #')&&str_contains($popup,'Название:')&&str_contains($popup,'Условия:')&&str_contains($popup,'Действие:')&&str_contains($popup,'Приоритет:')&&str_contains($popup,'Статус правила:'),'blue operation trace exposes complete applied rule summary');
foreach(['counterparty_inn','counterparty_id','counterparty_type','purpose_contains','invoice_number_pattern','purpose_regex','amount_from','amount_to','bank_account_id','target_employee_name_snapshot','target_cash_flow_center_id','target_dds_category_id','target_counterparty_id','target_counterparty_type'] as $field){ok(str_contains($popup,"classificationRule['".$field."']"),'rule trace includes persisted field '.$field);}
ok(str_contains($popup,'правило не найдено в текущем реестре'),'rule trace fails informatively when historical rule is unavailable');
ok(str_contains($filters,'.bank-row-manual>td')&&str_contains($filters,'#eff8ed'),'manual rows have soft green whole-row background');
ok(str_contains($filters,'.bank-row-unallocated>td')&&str_contains($filters,'#fff0ed'),'problem rows preserve red background');
ok(str_contains($filters,"status==='MANUAL'")&&str_contains($filters,"status==='AUTO'"),'row class derives from persisted classification status after refresh');
ok(str_contains($filters,"if(status==='MANUAL') row.classList.add('bank-row-manual')"),'MANUAL receives dedicated row class');
$tx=['id'=>10,'account_id'=>2,'credit_amount'=>'0.00','debit_amount'=>'1500.00','counterparty_inn'=>'7701234567','purpose'=>'Оплата услуг'];
$base=['active'=>1,'deleted_at'=>null,'priority'=>500,'direction'=>'EXPENSE','bank_account_id'=>2,'counterparty_inn'=>'7701234567','purpose_contains'=>'Оплата','action_type'=>'categorize','auto_apply'=>1];
$a=$base;$a['id']=101;$b=$base;$b['id']=102;$conflict=S::selectBestMatchingRule([$a,$b],$tx);ok(!empty($conflict['conflict']),'equal maximum priority remains conflict');
$expense=$base;$expense['id']=201;$expense['action_type']='employee_cash_settlement';$income=$expense;$income['id']=202;$income['direction']='INCOME';$txIncome=$tx;$txIncome['debit_amount']='0.00';$txIncome['credit_amount']='1500.00';ok(!empty(S::applyRuleToTransaction(null,$expense,$tx)['matched']),'employee expense rule matches bank debit');ok(!empty(S::applyRuleToTransaction(null,$income,$txIncome)['matched']),'employee income rule matches bank credit');
echo "FINANCE_BANK_OPERATION_POPUP_REGRESSION_OK\n";
