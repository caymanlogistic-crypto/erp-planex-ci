<?php
use App\Service\FinanceEmployeeClientReceiptEditService;
use App\Service\FinanceEmployeeDirectTransferEditService;

if (empty($selectedEmployee) || ($selectedRef ?? '') === '') return;

$clientEditByMovement = FinanceEmployeeClientReceiptEditService::fetchForEmployee($pdo, (string)$selectedRef);
$directTransferByMovement = FinanceEmployeeDirectTransferEditService::fetchForEmployee($pdo, (string)$selectedRef);
$invoiceEditByMovement = [];
foreach (($employeeInvoicePayments ?? []) as $event) {
    if ((string)($event['status'] ?? '') !== 'POSTED') continue;
    $mid=(int)($event['employee_movement_id']??0);
    if($mid>0)$invoiceEditByMovement[$mid]=$event;
}
$personalEditByMovement = [];
foreach (($employeePersonalExpenses ?? []) as $event) {
    if ((string)($event['status'] ?? '') !== 'POSTED') continue;
    $mid=(int)($event['employee_movement_id']??0);
    if($mid>0)$personalEditByMovement[$mid]=$event;
}

$payloadForRow = static function(array $row) use ($clientEditByMovement,$directTransferByMovement,$invoiceEditByMovement,$personalEditByMovement): ?array {
    $mid=(int)($row['id']??0);
    if(isset($clientEditByMovement[$mid])){
        $e=$clientEditByMovement[$mid];
        if((string)($e['status']??'')!=='POSTED')return null;
        return ['kind'=>'client','movement_id'=>$mid,'invoice_id'=>(int)$e['invoice_id'],'invoice_number'=>(string)($e['invoice_number']??''),'counterparty_name'=>(string)($e['counterparty_name']??''),'operation_date'=>(string)$e['operation_date'],'amount'=>(string)$e['amount'],'comment'=>(string)($e['comment']??'')];
    }
    if(isset($invoiceEditByMovement[$mid])){
        $e=$invoiceEditByMovement[$mid];
        return ['kind'=>'invoice','event_id'=>(int)$e['id']];
    }
    if(isset($personalEditByMovement[$mid])){
        $e=$personalEditByMovement[$mid];
        return ['kind'=>'personal','event_id'=>(int)$e['id']];
    }
    if(strtoupper((string)($row['source_type']??''))==='COMPANY_FUNDED') return ['kind'=>'company_funded','movement_id'=>$mid];
    if(isset($directTransferByMovement[$mid])) return ['kind'=>'transfer','data'=>$directTransferByMovement[$mid]];
    if(!empty($row['employee_transfer'])) return ['kind'=>'transfer','data'=>$row['employee_transfer']];
    return null;
};

$editorMonths=[];
foreach(($ledger??[]) as $row){$month=substr((string)($row['operation_date']??''),0,7);$editorMonths[$month][]=$row;}
krsort($editorMonths);
$orderedPayloads=[];
foreach($editorMonths as $rows){foreach($rows as $row)$orderedPayloads[]=$payloadForRow($row);}
?>
<style>
.employee-report-table tbody tr.employee-ledger-editable{cursor:pointer}
.employee-report-table tbody tr.employee-ledger-editable:hover{background:var(--surface-hover,#f5f2eb)}
.employee-report-table tbody tr.is-muted{display:none!important}
.employee-event-section{display:none!important}
</style>

<div id="employee-client-edit-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="employee-client-edit-title">
  <div class="modal modal-md">
    <div class="modal-head"><span class="modal-title" id="employee-client-edit-title">Изменить получение от клиента</span><button type="button" class="modal-close" data-client-edit-close>&times;</button></div>
    <form method="post" action="<?= app_url('/company/finance/employee-payments/client-receipt/update') ?>" id="employee-client-edit-form">
      <?= csrfField() ?><input type="hidden" name="employee_ref" value="<?= e((string)$selectedRef) ?>"><input type="hidden" name="movement_id" id="employee-client-edit-movement">
      <div class="modal-body">
        <div class="field"><label class="field-label">Счёт клиента <span class="field-required">*</span></label><select class="field-select" name="invoice_id" id="employee-client-edit-invoice" required><option value="">— Выберите счёт —</option><?php foreach(($clientInvoices??[]) as $invoice): ?><option value="<?= (int)$invoice['id'] ?>">№<?= e((string)($invoice['number']??$invoice['id'])) ?> · <?= e((string)$invoice['counterparty_display_name']) ?> · остаток <?= e($fmt($invoice['remaining_amount']??'0')) ?> ₽</option><?php endforeach; ?></select></div>
        <div class="form-grid two-cols"><div class="field"><label class="field-label">Дата <span class="field-required">*</span></label><input class="field-input" type="date" name="operation_date" id="employee-client-edit-date" required></div><div class="field"><label class="field-label">Сумма <span class="field-required">*</span></label><input class="field-input" name="amount" id="employee-client-edit-amount" inputmode="decimal" required></div></div>
        <div class="field"><label class="field-label">Комментарий</label><textarea class="field-input" name="comment" id="employee-client-edit-comment" rows="3"></textarea></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-ghost fs-delete-action" form="employee-client-delete-form">Удалить</button><button type="button" class="btn btn-secondary" data-client-edit-close>Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div>
    </form>
    <form method="post" action="<?= app_url('/company/finance/employee-payments/client-receipt/cancel') ?>" id="employee-client-delete-form" onsubmit="return confirm('Удалить получение от клиента? Счёт и обязательства будут пересчитаны.');"><?= csrfField() ?><input type="hidden" name="employee_ref" value="<?= e((string)$selectedRef) ?>"><input type="hidden" name="movement_id" id="employee-client-delete-movement"><input type="hidden" name="reason" value="Удалено пользователем из журнала взаиморасчётов"></form>
  </div>
</div>

<div id="employee-transfer-edit-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="employee-transfer-edit-title">
  <div class="modal modal-md">
    <div class="modal-head"><span class="modal-title" id="employee-transfer-edit-title">Изменить передачу сотруднику</span><button type="button" class="modal-close" data-transfer-edit-close>&times;</button></div>
    <form method="post" action="<?= app_url('/company/finance/employee-payments/transfer/update') ?>" id="employee-transfer-edit-form">
      <?= csrfField() ?><input type="hidden" name="transfer_group_id" id="employee-transfer-edit-group"><input type="hidden" name="return_employee_ref" value="<?= e((string)$selectedRef) ?>">
      <div class="modal-body">
        <div class="form-grid two-cols"><div class="field"><label class="field-label">От сотрудника <span class="field-required">*</span></label><select class="field-select" name="source_employee_ref" id="employee-transfer-edit-source" required><?php foreach(($employees??[]) as $employee): ?><option value="<?= e((string)$employee['ref']) ?>"><?= e((string)$employee['full_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label class="field-label">Кому <span class="field-required">*</span></label><select class="field-select" name="target_employee_ref" id="employee-transfer-edit-target" required><?php foreach(($employees??[]) as $employee): ?><option value="<?= e((string)$employee['ref']) ?>"><?= e((string)$employee['full_name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-grid two-cols"><div class="field"><label class="field-label">Дата <span class="field-required">*</span></label><input class="field-input" type="date" name="operation_date" id="employee-transfer-edit-date" required></div><div class="field"><label class="field-label">Сумма <span class="field-required">*</span></label><input class="field-input" name="amount" id="employee-transfer-edit-amount" inputmode="decimal" required></div></div>
        <div class="field"><label class="field-label">Основание</label><input class="field-input" name="purpose" id="employee-transfer-edit-purpose"></div><div class="field"><label class="field-label">Комментарий</label><textarea class="field-input" name="comment" id="employee-transfer-edit-comment" rows="3"></textarea></div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-ghost fs-delete-action" form="employee-transfer-delete-form">Удалить</button><button type="button" class="btn btn-secondary" data-transfer-edit-close>Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div>
    </form>
    <form method="post" action="<?= app_url('/company/finance/employee-payments/transfer/delete') ?>" id="employee-transfer-delete-form" onsubmit="return confirm('Удалить передачу между сотрудниками?');"><?= csrfField() ?><input type="hidden" name="return_employee_ref" value="<?= e((string)$selectedRef) ?>"><input type="hidden" name="transfer_group_id" id="employee-transfer-delete-group"><input type="hidden" name="reason" value="Удалено пользователем из журнала взаиморасчётов"></form>
  </div>
</div>

<script>
(function(){
 const payloads=<?= json_encode($orderedPayloads,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
 const rows=Array.from(document.querySelectorAll('.employee-report-table tbody tr'));
 const clientModal=document.getElementById('employee-client-edit-modal');
 const transferModal=document.getElementById('employee-transfer-edit-modal');
 const closeModal=(m)=>m&&m.classList.remove('is-open');
 document.querySelectorAll('[data-client-edit-close]').forEach(b=>b.addEventListener('click',()=>closeModal(clientModal)));
 document.querySelectorAll('[data-transfer-edit-close]').forEach(b=>b.addEventListener('click',()=>closeModal(transferModal)));
 [clientModal,transferModal].forEach(m=>m&&m.addEventListener('click',e=>{if(e.target===m)closeModal(m);}));

 const openClient=(p)=>{
   const select=document.getElementById('employee-client-edit-invoice');
   const wanted=String(p.invoice_id||'');
   if(wanted&&!Array.from(select.options).some(o=>o.value===wanted)){
     const o=document.createElement('option');o.value=wanted;o.textContent='№'+String(p.invoice_number||wanted)+' · '+String(p.counterparty_name||'Клиент')+' · текущий счёт';select.appendChild(o);
   }
   document.getElementById('employee-client-edit-movement').value=p.movement_id||'';
   document.getElementById('employee-client-delete-movement').value=p.movement_id||'';
   select.value=wanted;document.getElementById('employee-client-edit-date').value=p.operation_date||'';
   document.getElementById('employee-client-edit-amount').value=String(p.amount||'').replace('.',',');
   document.getElementById('employee-client-edit-comment').value=p.comment||'';
   clientModal.classList.add('is-open');
 };
 const openTransfer=(p)=>{
   const d=p.data||{};document.getElementById('employee-transfer-edit-group').value=d.transfer_group_id||'';document.getElementById('employee-transfer-delete-group').value=d.transfer_group_id||'';
   document.getElementById('employee-transfer-edit-source').value=d.source_employee_ref||'';document.getElementById('employee-transfer-edit-target').value=d.target_employee_ref||'';
   document.getElementById('employee-transfer-edit-date').value=d.operation_date||'';document.getElementById('employee-transfer-edit-amount').value=String(d.amount||'').replace('.',',');
   document.getElementById('employee-transfer-edit-purpose').value=d.purpose||'';document.getElementById('employee-transfer-edit-comment').value=d.comment||'';
   transferModal.classList.add('is-open');
 };
 const openInvoice=(p)=>{
   const candidates=Array.from(document.querySelectorAll('[data-invoice-payment-edit]'));
   const button=candidates.find(btn=>{try{return Number(JSON.parse(btn.dataset.invoicePaymentEdit||'{}').id)===Number(p.event_id);}catch(e){return false;}});
   if(button)button.click();
 };
 const bindPersonalEditor=(host)=>{
   const overlay=host.querySelector('.employee-personal-expense-modal');
   if(!overlay)return false;
   overlay.querySelectorAll('[data-personal-expense-close]').forEach(btn=>btn.addEventListener('click',()=>{host.innerHTML='';}));
   overlay.addEventListener('click',e=>{if(e.target===overlay)host.innerHTML='';});
   const form=overlay.querySelector('[data-personal-expense-form]');
   const cfu=overlay.querySelector('[data-personal-expense-cfu]');
   const dds=overlay.querySelector('[data-personal-expense-dds]');
   if(form&&cfu&&dds){
     let map={};try{map=JSON.parse(form.dataset.allowedExpenseDdsMap||'{}');}catch(e){}
     const all=Array.from(dds.options).map(o=>({value:o.value,text:o.textContent}));
     const sync=()=>{
       const selected=dds.dataset.selectedDds||dds.value;dds.innerHTML='';
       const first=document.createElement('option');first.value='';first.textContent='— Выберите статью —';dds.appendChild(first);
       const allowed=(map[String(cfu.value)]||[]).map(String);
       all.forEach(item=>{if(item.value&&allowed.includes(String(item.value))){const o=document.createElement('option');o.value=item.value;o.textContent=item.text;if(String(item.value)===String(selected))o.selected=true;dds.appendChild(o);}});
       dds.dataset.selectedDds='';
     };
     cfu.addEventListener('change',sync);sync();
   }
   const cancel=overlay.querySelector('[data-personal-expense-cancel-event]');
   if(cancel&&form){
     cancel.addEventListener('click',()=>{
       if(!confirm('Удалить прочий расход сотрудника?'))return;
       const token=form.querySelector('input[name="_csrf_token"]');
       const f=document.createElement('form');f.method='post';
       f.action='<?= e(app_url('/company/finance/employee-payments/personal-expense/')) ?>'+cancel.dataset.personalExpenseCancelEvent+'/cancel';
       const fields={_csrf_token:token?token.value:'',employee_ref:cancel.dataset.employeeRef||'<?= e((string)$selectedRef) ?>',reason:'Удалено пользователем из журнала взаиморасчётов'};
       Object.entries(fields).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;f.appendChild(input);});
       document.body.appendChild(f);f.submit();
     });
   }
   return true;
 };
 const openPersonal=async(p)=>{
   const host=document.getElementById('employee-personal-expense-host');
   if(!host)throw new Error('Не найден контейнер формы прочего расхода.');
   const url='<?= e(app_url('/company/finance/employee-payments/personal-expense/')) ?>'+String(p.event_id)+'/edit';
   const response=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});
   const html=await response.text();
   if(!response.ok)throw new Error(html.replace(/<[^>]+>/g,' ').trim()||'Не удалось открыть прочий расход.');
   host.innerHTML=html;
   if(!bindPersonalEditor(host))throw new Error('Форма прочего расхода не получена.');
 };
 rows.forEach((row,i)=>{
   const p=payloads[i];if(!p)return;row.classList.add('employee-ledger-editable');row.title='Двойной щелчок — изменить или удалить';
   row.addEventListener('dblclick',()=>{
     if(p.kind==='client')openClient(p);
     else if(p.kind==='transfer')openTransfer(p);
     else if(p.kind==='invoice')openInvoice(p);
     else if(p.kind==='personal')openPersonal(p).catch(error=>{console.error(error);alert(error.message||'Не удалось открыть прочий расход.');});
     else if(p.kind==='company_funded'&&window.employeeCompanyFundedUnmark)window.employeeCompanyFundedUnmark(p.movement_id);
   });
 });
 document.querySelectorAll('.employee-report-table').forEach(table=>{
   const visible=Array.from(table.querySelectorAll('tbody tr')).filter(row=>!row.classList.contains('is-muted')).length;
   const scroll=table.closest('.table-scroll');
   const head=scroll&&scroll.previousElementSibling;
   const caption=head&&head.querySelector('.employee-month-title')?head.querySelector('.employee-month-title').nextElementSibling:null;
   if(caption)caption.textContent=caption.textContent.replace(/^\d+ операций/u,visible+' операций');
 });
})();
</script>
