<?php
use App\Service\FinanceEmployeePaymentService;

if (empty($selectedEmployee) || ($selectedRef ?? '') === '') return;
$companyFundedCandidates = $companyFundedCandidates ?? [];
$fmtCompanyFunded = static fn($v): string => FinanceEmployeePaymentService::formatMoney($v);
?>
<button type="button" class="btn btn-primary btn--toolbar" id="employee-company-funded-open" <?= $companyFundedCandidates === [] ? 'disabled' : '' ?> title="<?= $companyFundedCandidates === [] ? 'Нет доступных банковских списаний' : 'Компания оплатила расход за сотрудника' ?>">Оплачено компанией</button>

<div id="employee-company-funded-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="employee-company-funded-title">
  <div class="modal modal-md">
    <div class="modal-head"><span class="modal-title" id="employee-company-funded-title">Расход сотрудника, оплаченный компанией</span><button type="button" class="modal-close" data-company-funded-close>&times;</button></div>
    <form method="post" action="<?= app_url('/company/finance/employee-payments/company-funded-expense/create') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="employee_ref" value="<?= e((string)$selectedRef) ?>">
      <div class="modal-body">
        <div class="form-alert alert-info">Банковский платёж останется обычным расходом компании и продолжит участвовать в контроле закрывающих документов. У сотрудника появится долг перед компанией на сумму выбранного платежа.</div>
        <div class="field"><label class="field-label">Сотрудник</label><input class="field-input" value="<?= e((string)($selectedEmployee['full_name']??'')) ?>" readonly></div>
        <div class="field"><label class="field-label">Банковское списание <span class="field-required">*</span></label><select class="field-select" name="bank_transaction_id" required><option value="">— Выберите платёж —</option><?php foreach($companyFundedCandidates as $tx): ?><option value="<?= (int)$tx['id'] ?>"><?= e(date('d.m.Y',strtotime((string)$tx['operation_date']))) ?> · <?= e((string)($tx['counterparty_name']?:'Без контрагента')) ?> · <?= e($fmtCompanyFunded($tx['debit_amount']??$tx['amount']??'0')) ?> ₽<?= !empty($tx['document_number'])?' · №'.e((string)$tx['document_number']):'' ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="field-label">Комментарий</label><textarea class="field-input" name="comment" rows="3" placeholder="Например: юридические услуги, расход относится к сотруднику"></textarea></div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-secondary" data-company-funded-close>Отмена</button><button type="submit" class="btn btn-primary">Отразить за сотрудником</button></div>
    </form>
  </div>
</div>

<form method="post" action="<?= app_url('/company/finance/employee-payments/company-funded-expense/unmark') ?>" id="employee-company-funded-unmark-form" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="employee_ref" value="<?= e((string)$selectedRef) ?>">
  <input type="hidden" name="movement_id" value="">
</form>

<script>
(function(){
 const button=document.getElementById('employee-company-funded-open'),modal=document.getElementById('employee-company-funded-modal'),head=document.querySelector('.employee-report .page-head-right');
 if(button&&head)head.appendChild(button);
 const close=()=>modal&&modal.classList.remove('is-open');
 if(button&&modal)button.addEventListener('click',()=>{if(!button.disabled)modal.classList.add('is-open');});
 document.querySelectorAll('[data-company-funded-close]').forEach(b=>b.addEventListener('click',close));
 if(modal)modal.addEventListener('click',e=>{if(e.target===modal)close();});
 window.employeeCompanyFundedUnmark=function(movementId){
   const form=document.getElementById('employee-company-funded-unmark-form');
   if(!form||!movementId)return;
   if(!confirm('Снять отметку «оплачено компанией за сотрудника»? Сам банковский платёж и его закрывающие документы не изменятся.'))return;
   const input=form.querySelector('input[name="movement_id"]');
   if(input)input.value=String(movementId);
   form.submit();
 };
})();
</script>
