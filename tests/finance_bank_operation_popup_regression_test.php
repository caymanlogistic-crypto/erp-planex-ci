<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/Service/FinanceMatchingRuleService.php';
use App\Service\FinanceMatchingRuleService as S;
function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException('FAIL: '.$message);}

$root=dirname(__DIR__);
$manual=file_get_contents($root.'/app/Service/FinanceMatchingRuleManualTrait.php');
$submit=file_get_contents($root.'/app/Http/Controllers/Company/BankFinanceActions/classifySubmit.php');
$popup=file_get_contents($root.'/app/View/partials/company_bank_transaction_classify_form.php');
$employee=file_get_contents($root.'/app/Service/FinanceEmployeeBankSettlementService.php');
$batch=file_get_contents($root.'/app/Service/FinanceMatchingRuleBatchTrait.php');
$filters=file_get_contents($root.'/app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php');
$rulesForm=file_get_contents($root.'/app/View/partials/company_finance_matching_rule_form.php');

// 1. Single operation is a persisted manual action, never a pseudo-rule.
ok(str_contains($manual,"$mode!=='manual'")||str_contains($manual,"$mode!==\'manual\'"),'manual executor rejects non-manual mode');
ok(!str_contains($manual,'self::createRule('),'manual executor cannot create matching rule');
ok(str_contains($manual,"classification_status='MANUAL'")&&str_contains($manual,'classification_locked=1'),'manual categorize persists MANUAL lock');
ok(str_contains($manual,"'rule_id'=>null")&&str_contains($manual,"'status'=>'MANUAL'"),'manual result is explicitly rule-less');

// 2. Create-rule popup path uses the canonical rule model and engine.
ok(str_contains($submit,'FinanceMatchingRuleService::createRule'),'popup rule mode creates canonical matching rule');
ok(str_contains($submit,'FinanceMatchingRuleService::applyAutoMatchToOperation'),'new popup rule is executed by canonical engine');
ok(str_contains($submit,"if($mode==='rule')")||str_contains($submit,"if(\$mode==='rule')"),'controller has explicit rule branch');
ok(str_contains($submit,'manualClassifyBankTransaction'),'controller has explicit manual branch');
ok(str_contains($submit,'resolveActiveEmployee'),'employee resolution is shared with rules screen');

// 3. Manual BANK <-> EMPLOYEE direct settlement persists manual protection and BANK provenance.
ok(str_contains($manual,"$action==='employee_cash_settlement'")||str_contains($manual,"\$action==='employee_cash_settlement'"),'manual action exposes employee settlement');
ok(str_contains($manual,'FinanceEmployeeBankSettlementService::settleBankTransaction'),'manual employee action delegates to shared bank settlement service');
ok(str_contains($employee,"$isManual = $ruleId === null")||str_contains($employee,'$isManual = $ruleId === null'),'employee service derives manual mode from absent rule id');
ok(str_contains($employee,"$classificationStatus = $isManual ? 'MANUAL' : 'AUTO'")||str_contains($employee,"\$classificationStatus = \$isManual ? 'MANUAL' : 'AUTO'"),'employee settlement distinguishes manual and auto source');
ok(str_contains($employee,"$classificationLocked = $isManual ? 1 : 0")||str_contains($employee,"\$classificationLocked = \$isManual ? 1 : 0"),'manual employee settlement is locked');
ok(str_contains($employee,"'BANK'"),'employee movement source remains BANK');
ok(str_contains($employee,"$movementType = $originalType === 'EXPENSE' ? 'PAYMENT' : 'RETURN'")||str_contains($employee,"\$movementType = \$originalType === 'EXPENSE' ? 'PAYMENT' : 'RETURN'"),'bank debit means payment to employee and bank credit means return');
ok(!str_contains($employee,'finance_cash_resolutions'),'BANK <-> EMPLOYEE does not reintroduce CASH');

// 4. Batch engine cannot overwrite single-operation locks.
ok(str_contains($batch,'classification_locked=0'),'batch selection excludes locked bank transactions');
ok(str_contains($batch,"classification_status<>'MANUAL'")||str_contains($batch,"classification_status != 'MANUAL'")||str_contains($batch,"classification_status!='MANUAL'"),'batch selection excludes MANUAL finance operations');

// 5. Rule fields available from popup: active action matrix + current engine conditions.
foreach(['action_type','target_employee_ref','target_cash_flow_center_id','target_dds_category_id','direction','counterparty_inn','purpose_contains','invoice_number_pattern','purpose_regex','bank_account_id','amount_from','amount_to','priority'] as $field){
 ok(str_contains($popup,'name="'.$field.'"'),'popup exposes rule field '.$field);
}
ok(str_contains($popup,'value="categorize"')&&str_contains($popup,'value="employee_cash_settlement"'),'popup exposes same active rule types as rules screen');
ok(str_contains($rulesForm,'employee_cash_settlement')&&str_contains($rulesForm,'categorize'),'separate rules screen remains intact');
ok(str_contains($popup,'tx-rule-settings')&&str_contains($popup,'distribution_mode'),'rule-only conditions are gated by selected mode');

// 6. Persisted presentation semantics: problem red, manual green, auto ordinary.
ok(str_contains($filters,'.bank-row-manual>td')&&str_contains($filters,'#eff8ed'),'manual rows have soft green whole-row background');
ok(str_contains($filters,'.bank-row-unallocated>td')&&str_contains($filters,'#fff0ed'),'problem rows preserve red background');
ok(str_contains($filters,"status==='MANUAL'")&&str_contains($filters,"status==='AUTO'"),'row class derives from persisted classification status after refresh');
ok(str_contains($filters,"status==='MANUAL'?'bank-row-manual'")||str_contains($filters,"status==='MANUAL' ? 'bank-row-manual'")||str_contains($filters,"'MANUAL'?'bank-row-manual'"),'MANUAL receives dedicated row class');

// 7. Equal maximum priority conflict behavior remains unchanged in pure engine resolution.
$tx=['id'=>10,'account_id'=>2,'credit_amount'=>'0.00','debit_amount'=>'1500.00','counterparty_inn'=>'7701234567','purpose'=>'Оплата услуг'];
$base=['active'=>1,'deleted_at'=>null,'priority'=>500,'direction'=>'EXPENSE','bank_account_id'=>2,'counterparty_inn'=>'7701234567','purpose_contains'=>'Оплата','action_type'=>'categorize','auto_apply'=>1];
$a=$base;$a['id']=101;$b=$base;$b['id']=102;
$conflict=S::selectBestMatchingRule([$a,$b],$tx);
ok(!empty($conflict['conflict']),'equal maximum priority remains conflict');

// 8. Automatic employee rule keeps both directions in the same action model.
$expense=$base;$expense['id']=201;$expense['action_type']='employee_cash_settlement';
$income=$expense;$income['id']=202;$income['direction']='INCOME';
$txIncome=$tx;$txIncome['debit_amount']='0.00';$txIncome['credit_amount']='1500.00';
ok(!empty(S::applyRuleToTransaction(null,$expense,$tx)['matched']),'employee expense rule matches bank debit');
ok(!empty(S::applyRuleToTransaction(null,$income,$txIncome)['matched']),'employee income rule matches bank credit');

echo "FINANCE_BANK_OPERATION_POPUP_REGRESSION_OK\n";
