<?php
$credit=(string)($tx['credit_amount']??'0.00');$debit=(string)($tx['debit_amount']??'0.00');$isIncome=((float)$credit)>0;$amount=$isIncome?$credit:$debit;$amountFormatted=number_format((float)$amount,2,',',' ');$directionLabel=$isIncome?'Поступление':'Списание';$directionValue=$isIncome?'INCOME':'EXPENSE';$selectedCfu=(int)($tx['cash_flow_center_id']??0);$selectedDds=(int)($tx['dds_category_id']??0);$classificationStatus=strtoupper((string)($tx['classification_status']??'UNALLOCATED'));$ruleId=(int)($tx['classification_rule_id']??0);$isAuto=$classificationStatus==='AUTO';$isManual=$classificationStatus==='MANUAL';$clearFormId='tx-clear-classification-form-'.(int)$tx['id'];$clearModalId='tx-clear-classification-modal-'.(int)$tx['id'];$allowedMapJson=json_encode($ddsAllowedMap??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($allowedMapJson===false)$allowedMapJson='{}';$defaultInn=trim((string)($tx['counterparty_inn']??''));$defaultBank=(int)($tx['account_id']??0);
$classificationRule=is_array($classificationRule??null)?$classificationRule:null;$ruleConditionParts=[];$ruleActionParts=[];$ruleName='';
if($ruleId>0&&$classificationRule!==null){
 $ruleName=trim((string)($classificationRule['name']??''));$ruleDirection=strtoupper(trim((string)($classificationRule['direction']??'')));if($ruleDirection!=='')$ruleConditionParts[]='Направление: '.($ruleDirection==='INCOME'?'Поступление':($ruleDirection==='EXPENSE'?'Списание':$ruleDirection));
 $ruleBankId=(int)($classificationRule['bank_account_id']??0);if($ruleBankId>0){$ruleBankLabel='#'.$ruleBankId;foreach(($bankAccounts??[]) as $account){if((int)($account['id']??0)!==$ruleBankId)continue;$candidate=trim(trim((string)($account['bank_name']??'')).' — '.trim((string)($account['account_number']??'')),' —');if($candidate!=='')$ruleBankLabel=$candidate;break;}$ruleConditionParts[]='Банковский счёт: '.$ruleBankLabel;}
 $ruleInn=trim((string)($classificationRule['counterparty_inn']??''));if($ruleInn!=='')$ruleConditionParts[]='ИНН: '.$ruleInn;
 $ruleCounterpartyId=(int)($classificationRule['counterparty_id']??0);$ruleCounterpartyType=trim((string)($classificationRule['counterparty_type']??''));if($ruleCounterpartyId>0)$ruleConditionParts[]='Контрагент: '.($ruleCounterpartyType!==''?$ruleCounterpartyType.' ':'').'#'.$ruleCounterpartyId;elseif($ruleCounterpartyType!=='')$ruleConditionParts[]='Тип контрагента: '.$ruleCounterpartyType;
 $purposeContains=trim((string)($classificationRule['purpose_contains']??''));if($purposeContains!=='')$ruleConditionParts[]='Назначение содержит «'.$purposeContains.'»';$invoicePattern=trim((string)($classificationRule['invoice_number_pattern']??''));if($invoicePattern!=='')$ruleConditionParts[]='Счёт содержит «'.$invoicePattern.'»';$purposeRegex=trim((string)($classificationRule['purpose_regex']??''));if($purposeRegex!=='')$ruleConditionParts[]='Regex назначения: '.$purposeRegex;
 $amountFrom=$classificationRule['amount_from']??null;$amountTo=$classificationRule['amount_to']??null;$hasAmountFrom=$amountFrom!==null&&$amountFrom!=='';$hasAmountTo=$amountTo!==null&&$amountTo!=='';if($hasAmountFrom&&$hasAmountTo)$ruleConditionParts[]='Сумма: '.number_format((float)$amountFrom,2,',',' ').'–'.number_format((float)$amountTo,2,',',' ').' ₽';elseif($hasAmountFrom)$ruleConditionParts[]='Сумма от '.number_format((float)$amountFrom,2,',',' ').' ₽';elseif($hasAmountTo)$ruleConditionParts[]='Сумма до '.number_format((float)$amountTo,2,',',' ').' ₽';
 $ruleAction=(string)($classificationRule['action_type']??'');$ruleActionLabels=['categorize'=>'Обычное разнесение','categorize_to_cash'=>'Обычное разнесение (исторический режим)','match_invoice'=>'Сопоставление со счётом','match_counterparty'=>'Сопоставление с контрагентом','transfer_to_cash'=>'Перевод в кассу (исторический режим)','employee_cash_settlement'=>'Взаиморасчёт с сотрудником'];$ruleActionParts[]=$ruleActionLabels[$ruleAction]??($ruleAction!==''?$ruleAction:'Действие не указано');
 $employeeName=trim((string)($classificationRule['target_employee_name_snapshot']??''));if($employeeName!=='')$ruleActionParts[]='Сотрудник: '.$employeeName;
 $targetCfuId=(int)($classificationRule['target_cash_flow_center_id']??0);if($targetCfuId>0){$targetCfuLabel='#'.$targetCfuId;foreach(($cfu??[]) as $row){if((int)($row['id']??0)===$targetCfuId){$targetCfuLabel=(string)($row['name']??$targetCfuLabel);break;}}$ruleActionParts[]='ЦФУ: '.$targetCfuLabel;}
 $targetDdsId=(int)($classificationRule['target_dds_category_id']??0);if($targetDdsId>0){$targetDdsLabel='#'.$targetDdsId;foreach(($dds??[]) as $row){if((int)($row['id']??0)===$targetDdsId){$targetDdsLabel=(string)($row['name']??$targetDdsLabel);break;}}$ruleActionParts[]='Статья ДДС: '.$targetDdsLabel;}
 $targetCounterpartyId=(int)($classificationRule['target_counterparty_id']??0);$targetCounterpartyType=trim((string)($classificationRule['target_counterparty_type']??''));if($targetCounterpartyId>0)$ruleActionParts[]='Целевой контрагент: '.($targetCounterpartyType!==''?$targetCounterpartyType.' ':'').'#'.$targetCounterpartyId;
 $targetCashId=(int)($classificationRule['target_cash_account_id']??0);if($targetCashId>0)$ruleActionParts[]='Касса: #'.$targetCashId;
}
$ruleConditionText=$ruleConditionParts!==[]?implode(' · ',$ruleConditionParts):'Условия не указаны';$ruleActionText=$ruleActionParts!==[]?implode(' · ',$ruleActionParts):'Действие не указано';
?>
<style>
.tx-classify{padding:14px 18px 0;border-top:1px solid var(--line-soft);background:var(--surface-form)}
.tx-classify-head{display:flex;justify-content:space-between;gap:16px;margin-bottom:12px}.tx-classify-title{font-size:13px;font-weight:700}.tx-classify-meta,.tx-classify-hint{color:var(--text-faint);font-size:10.5px;line-height:1.4}.tx-classify-amount{padding:4px 8px;border:1px solid var(--line-soft);background:var(--surface-strong);font-size:11px;font-weight:700}.tx-classify-state{margin-bottom:12px;padding:9px 11px;border:1px solid var(--line-soft);background:var(--surface-strong);font-size:11px;line-height:1.45}
.tx-rule-trace{margin-top:8px;padding-top:8px;font-size:11px;line-height:1.5}.tx-rule-trace-title{font-weight:700;margin-bottom:3px}.tx-rule-trace-row{overflow-wrap:anywhere}.tx-rule-trace-row strong{font-weight:700}.tx-rule-trace-muted{margin-top:3px;opacity:.76}
.tx-classify-fields,.tx-rule-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px}.tx-classify-mode{display:flex;gap:8px;margin:0 0 12px}.tx-classify-choice{flex:1;display:flex;align-items:flex-start;gap:8px;padding:9px 11px;border:1px solid var(--line-soft);background:var(--surface-strong);cursor:pointer;transition:border-color 90ms,background 90ms}.tx-classify-choice:hover{border-color:var(--line);background:var(--surface-field-alt)}.tx-classify-choice:has(input:checked){border-color:var(--accent-line);background:var(--accent-bg)}.tx-classify-choice input[type="radio"]{appearance:auto!important;-webkit-appearance:radio!important;width:13px!important;height:13px!important;min-width:13px!important;max-width:13px!important;flex:0 0 13px!important;margin:2px 0 0!important;padding:0!important;border:0!important;box-shadow:none!important;transform:none!important;accent-color:#8a4b12}.tx-classify-choice input[type="radio"]::before,.tx-classify-choice input[type="radio"]::after{content:none!important;display:none!important}
.tx-rule-settings{display:none;margin-top:12px;padding:11px 12px;border:1px solid var(--line-soft);background:var(--surface-strong)}.tx-allocation-form:has(input[name="distribution_mode"][value="rule"]:checked) .tx-rule-settings{display:block}.tx-rule-settings .tx-classify-meta{margin-bottom:10px}.tx-employee-target.is-hidden{display:none}.tx-classify-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;margin:14px -18px 0;padding:10px 18px;border-top:1px solid var(--line-soft);background:var(--surface-strong)}.tx-classify-actions .tx-return-rules{margin-right:auto}
@media(max-width:760px){.tx-classify-mode{flex-direction:column}.tx-classify-fields,.tx-rule-grid{grid-template-columns:1fr}}
</style>
<?php if(!empty($companyFundedAttribution)): ?>
<div class="form-alert alert-info" data-company-funded-attribution>
 <strong>Разнесено вручную · Оплачено компанией за сотрудника</strong><br>
 <?= e($companyFundedAttribution['employee_name']) ?> · <?= e(number_format((float)$companyFundedAttribution['amount'],2,',',' ')) ?> ₽<br>
 Платёж учтён во взаиморасчётах с сотрудником. Оплата контрагенту и закрывающие документы сохранены.
 <a href="<?= e(app_url('/company/finance/employee-payments').'?employee_ref='.rawurlencode((string)$companyFundedAttribution['employee_ref'])) ?>">Открыть взаиморасчёты</a>
</div>
<?php elseif(!empty($tx['is_internal_transfer'])): ?>
<div class="form-alert alert-info">
 <?php if(!empty($employeeMovement)): ?>Эта банковская операция уже отражена напрямую как взаиморасчёт BANK ↔ EMPLOYEE<?= $classificationStatus==='MANUAL'?' и зафиксирована вручную.':'.' ?><?php else: ?>Эта банковская операция уже оформлена как внутренний перевод и повторно не разносится.<?php endif; ?>
 <?php if($ruleId>0): ?>
  <div class="tx-rule-trace">
   <div class="tx-rule-trace-title"><?= $classificationStatus==='AUTO'?'Сработало правило #':'Правило разнесения #' ?><?= $ruleId ?></div>
   <?php if($classificationRule!==null): ?>
    <div class="tx-rule-trace-row"><strong>Название:</strong> <?= e($ruleName!==''?$ruleName:'Без названия') ?></div>
    <div class="tx-rule-trace-row"><strong>Условия:</strong> <?= e($ruleConditionText) ?></div>
    <div class="tx-rule-trace-row"><strong>Действие:</strong> <?= e($ruleActionText) ?></div>
    <div class="tx-rule-trace-row"><strong>Приоритет:</strong> <?= (int)($classificationRule['priority']??0) ?> · <strong>Статус правила:</strong> <?= !empty($classificationRule['active'])?'активно':'отключено' ?></div>
   <?php else: ?>
    <div class="tx-rule-trace-muted"><strong>Описание:</strong> правило не найдено в текущем реестре (возможно, удалено).</div>
   <?php endif; ?>
  </div>
 <?php endif; ?>
</div>
<?php else: ?>
<form class="tx-allocation-form" method="post" action="<?= app_url('/company/finance/bank-transactions/'.(int)$tx['id'].'/classify') ?>">
<?= csrfField() ?>
<input type="hidden" name="name" value="Правило из банковской операции #<?= (int)$tx['id'] ?>">
<input type="hidden" name="auto_apply" value="1">
<input type="hidden" name="target_cash_account_id" value="">
<section class="tx-classify">
 <div class="tx-classify-head"><div><div class="tx-classify-title">Разнесение операции</div><div class="tx-classify-meta">Операция #<?= (int)$tx['id'] ?> · <?= e($directionLabel) ?></div></div><div class="tx-classify-amount"><?= e($amountFormatted) ?> ₽</div></div>
 <?php if($isAuto): ?><div class="tx-classify-state"><strong>Сейчас разнесено автоматически<?= $ruleId>0?' · правило #'.$ruleId:'' ?></strong><br>Можно зафиксировать только эту операцию вручную или создать полноценное правило.</div><?php elseif($isManual): ?><div class="tx-classify-state"><strong>Ручное исключение</strong><br>Операция защищена от «Разнести по правилам». Кнопка «Вернуть под правила» снимает защиту.</div><?php endif; ?>
 <div class="tx-classify-mode">
  <label class="tx-classify-choice"><input type="radio" name="distribution_mode" value="manual" checked><span><strong>Только эту операцию</strong><br><span class="tx-classify-hint">Ручная команда только для выбранной строки. Правило не создаётся.</span></span></label>
  <label class="tx-classify-choice"><input type="radio" name="distribution_mode" value="rule"><span><strong>Создать правило</strong><br><span class="tx-classify-hint">Обычное постоянное правило из общего реестра.</span></span></label>
 </div>
 <div class="tx-classify-fields">
  <div class="field"><label class="field-label">Тип действия *</label><select class="field-select" name="action_type" required onchange="var on=this.value==='employee_cash_settlement',box=this.form.querySelector('[data-tx-employee-target]'),sel=box?box.querySelector('select'):null;if(box)box.classList.toggle('is-hidden',!on);if(sel){sel.disabled=!on;sel.required=on;}"><option value="categorize">Обычное разнесение</option><option value="employee_cash_settlement">Взаиморасчёт с сотрудником</option></select></div>
  <div class="field tx-employee-target is-hidden" data-tx-employee-target><label class="field-label">Сотрудник *</label><select class="field-select" name="target_employee_ref" disabled><option value="">— Выберите сотрудника —</option><?php foreach(($employeeUsers??[]) as $employee): ?><option value="<?= e((string)$employee['ref']) ?>"><?= e((string)$employee['full_name']) ?></option><?php endforeach; ?></select><div class="tx-classify-hint">Списание с банка = получено сотрудником; поступление = возврат сотрудником. BANK ↔ EMPLOYEE напрямую.</div></div>
  <div class="field"><label class="field-label">ЦФУ *</label><select class="field-select" name="target_cash_flow_center_id" id="bank-cfu-select" required data-dds-map="<?= e($allowedMapJson) ?>" data-selected-dds="<?= $selectedDds ?>"><option value="">— Выберите ЦФУ —</option><?php foreach($cfu as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $selectedCfu===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label class="field-label">Статья ДДС *</label><select class="field-select" name="target_dds_category_id" id="bank-dds-select" required <?= $selectedCfu<=0?'disabled':'' ?>><option value=""><?= $selectedCfu>0?'— Выберите статью —':'— Сначала выберите ЦФУ —' ?></option><?php foreach($dds as $row): ?><option value="<?= (int)$row['id'] ?>" data-dds-id="<?= (int)$row['id'] ?>" <?= $selectedDds===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></div>
 </div>
 <div class="tx-rule-settings">
  <div class="tx-classify-meta"><strong>Условия постоянного правила</strong><br>Эти поля относятся только к режиму «Создать правило» и сохраняются через общий FinanceMatchingRuleService.</div>
  <div class="tx-rule-grid">
   <div class="field"><label class="field-label">Направление</label><select class="field-select" name="direction"><option value="">Любое</option><option value="INCOME" <?= $directionValue==='INCOME'?'selected':'' ?>>Поступление</option><option value="EXPENSE" <?= $directionValue==='EXPENSE'?'selected':'' ?>>Списание</option></select></div>
   <div class="field"><label class="field-label">ИНН</label><input class="field-input" name="counterparty_inn" value="<?= e($defaultInn) ?>"></div>
   <div class="field"><label class="field-label">Назначение содержит</label><input class="field-input" name="purpose_contains"></div>
   <div class="field"><label class="field-label">Шаблон номера счёта</label><input class="field-input" name="invoice_number_pattern"></div>
   <div class="field"><label class="field-label">Regex назначения</label><input class="field-input" name="purpose_regex" placeholder="/выражение/ui"></div>
   <div class="field"><label class="field-label">Банковский счёт</label><select class="field-select" name="bank_account_id"><option value="">Любой счёт</option><?php foreach(($bankAccounts??[]) as $account): ?><option value="<?= (int)$account['id'] ?>" <?= $defaultBank===(int)$account['id']?'selected':'' ?>><?= e(trim((string)($account['bank_name']??'')).' — '.(string)($account['account_number']??'')) ?></option><?php endforeach; ?></select></div>
   <div class="field"><label class="field-label">Сумма от</label><input class="field-input" type="number" step="0.01" min="0" name="amount_from"></div>
   <div class="field"><label class="field-label">Сумма до</label><input class="field-input" type="number" step="0.01" min="0" name="amount_to"></div>
   <div class="field"><label class="field-label">Приоритет</label><input class="field-input" type="number" name="priority" min="1" max="100000" value="100"></div>
  </div>
 </div>
 <div class="tx-classify-actions">
  <?php if($isManual): ?><button type="button" class="btn btn-secondary tx-return-rules" onclick="window.openModal('<?= e($clearModalId) ?>')">Вернуть под правила</button><?php endif; ?>
  <button type="button" class="btn btn-ghost" data-close-modal="tx-detail-modal">Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button>
 </div>
</section>
</form>
<?php if($isManual): ?><form method="post" action="<?= app_url('/company/finance/bank-transactions/'.(int)$tx['id'].'/classification/delete') ?>" id="<?= e($clearFormId) ?>" class="is-hidden"><?= csrfField() ?></form><div id="<?= e($clearModalId) ?>" class="modal-overlay"><div class="modal modal-sm"><div class="modal-head"><span class="modal-title">Вернуть операцию под правила</span></div><div class="modal-body">Ручное исключение будет снято. Операция сразу заново пройдёт через действующие правила.</div><div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal="<?= e($clearModalId) ?>">Отмена</button><button type="submit" form="<?= e($clearFormId) ?>" class="btn btn-primary">Вернуть под правила</button></div></div></div><?php endif; ?>
<?php endif; ?>
