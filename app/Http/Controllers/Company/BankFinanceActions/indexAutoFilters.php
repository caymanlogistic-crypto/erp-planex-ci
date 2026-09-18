<?php
/**
 * Presentation wrapper for the canonical bank register action.
 *
 * The canonical bank action remains the single source of data and matching behavior.
 * This wrapper hardens the register UX and routes known clients/carriers to the
 * dedicated invoice-settlement popup on double click. Other rows keep the
 * existing generic classification popup.
 */
ob_start();
require base_path('app/Http/Controllers/Company/BankFinanceActions/index.php');
$html = ob_get_clean();

$style = <<<'HTML'
<style id="bank-register-auto-filter-style">
.bank-transactions-table th:nth-child(2),
.bank-transactions-table td:nth-child(2){display:none!important}
.bank-transactions-table th:nth-child(10),
.bank-transactions-table td:nth-child(10){display:none!important}
.bank-transactions-table{table-layout:fixed;width:100%}
.bank-transactions-table th:nth-child(1),.bank-transactions-table td:nth-child(1){width:82px}
.bank-transactions-table th:nth-child(3),.bank-transactions-table td:nth-child(3){width:17%}
.bank-transactions-table th:nth-child(4),.bank-transactions-table td:nth-child(4){width:112px}
.bank-transactions-table th:nth-child(5),.bank-transactions-table td:nth-child(5){width:38%;min-width:390px;white-space:normal;overflow-wrap:anywhere}
.bank-transactions-table tbody tr.bank-row-unallocated>td{background:#fff0ed!important}
.bank-transactions-table tbody tr.bank-row-unallocated:hover>td{background:#fce8e4!important}
.bank-transactions-table tbody tr.bank-row-manual>td{background:#eff8ed!important}
.bank-transactions-table tbody tr.bank-row-manual:hover>td{background:#e7f3e4!important}
.bank-transactions-table tbody tr.bank-row-auto>td{background:#fff!important}
.bank-transactions-table tbody tr.bank-row-auto:hover>td{background:#fff!important}
.bank-controls-filter button[type="submit"],
.bank-controls-filter a.btn,
.bank-controls-right>a.btn[data-bank-filter-reset]{display:none!important}
.bank-controls-filter{align-items:end}
.bank-controls-filter [name="q"]{min-width:230px}
#bank-invoice-settlement-modal .modal{width:min(1040px,calc(100vw - 48px));max-height:calc(100vh - 60px)}
.bank-settlement-summary{display:grid;grid-template-columns:1fr 1.4fr .8fr;gap:10px}
.bank-settlement-summary>div,.bank-settlement-purpose{border:1px solid var(--line-soft);background:var(--surface-strong);padding:10px 12px}
.bank-settlement-summary span,.bank-settlement-purpose>span{display:block;color:var(--text-faint);font-size:10px;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
.bank-settlement-summary strong{display:block;font-size:12px}.bank-settlement-summary small{display:block;color:var(--text-faint);margin-top:3px}
.bank-settlement-purpose{margin-top:10px;line-height:1.45}
.bank-settlement-table-wrap{margin-top:8px;border:1px solid var(--line-soft);max-height:330px;overflow:auto}
.bank-settlement-table{width:100%;border-collapse:collapse;table-layout:fixed}.bank-settlement-table th,.bank-settlement-table td{padding:8px;border-bottom:1px solid var(--line-soft);vertical-align:middle}
.bank-settlement-table th{font-size:10px;text-transform:uppercase;color:var(--text-faint);text-align:left;background:var(--surface-strong);position:sticky;top:0;z-index:1}
.bank-settlement-table .num{text-align:right;white-space:nowrap;width:130px}.bank-settlement-table .bank-settlement-check{text-align:center;width:66px}
.bank-settlement-table input[type="checkbox"]{appearance:auto!important;-webkit-appearance:checkbox!important;accent-color:#8a4b12;width:15px!important;height:15px!important;min-width:15px!important;max-width:15px!important;margin:0!important;padding:0!important;border:0!important;box-shadow:none!important;transform:none!important}
.bank-settlement-table .js-settlement-amount{width:112px;text-align:right;margin-left:auto}
.bank-settlement-link-meta{font-size:10.5px;color:var(--text-faint);margin-top:3px}.bank-settlement-total{display:flex;align-items:baseline;justify-content:flex-end;gap:10px;margin-top:10px}.bank-settlement-total strong{font-size:13px}.bank-settlement-total small{color:var(--text-faint)}
@media(max-width:900px){.bank-settlement-summary{grid-template-columns:1fr}.bank-settlement-table{min-width:760px}}
</style>
HTML;

$settlementModal = <<<'HTML'
<div id="bank-invoice-settlement-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal">
    <div class="modal-head"><span class="modal-title">Расчёты с контрагентом</span><button type="button" class="modal-close" data-close-modal="bank-invoice-settlement-modal" aria-label="Закрыть">×</button></div>
    <div data-bank-settlement-body><div class="modal-body"><div class="form-hint">Загрузка…</div></div></div>
  </div>
</div>
HTML;

$script = <<<'HTML'
<script id="bank-register-auto-filter-script">
(function(){
  function classificationFromLabel(value){
    var text=String(value||'').trim().toLocaleLowerCase('ru-RU');
    if(text.indexOf('вручную')!==-1) return 'MANUAL';
    if(text.indexOf('автоматически')!==-1) return 'AUTO';
    if(text.indexOf('не разнесено')!==-1) return 'UNALLOCATED';
    if(text.indexOf('конфликт')!==-1||text.indexOf('провер')!==-1) return 'NEEDS_REVIEW';
    return 'NEEDS_REVIEW';
  }

  function initClassificationPresentation(){
    var table=document.querySelector('.bank-transactions-table');
    if(!table||table.dataset.classificationPresentationReady==='1') return;
    table.dataset.classificationPresentationReady='1';
    var headers=Array.from(table.querySelectorAll('thead th'));
    var statusHeader=headers.find(function(th){return (th.textContent||'').trim()==='Статус';});
    if(!statusHeader) return;
    var statusIndex=statusHeader.cellIndex;
    Array.from(table.querySelectorAll('tbody tr[data-tx-id]')).forEach(function(row){
      var statusCell=row.cells[statusIndex];
      if(!statusCell) return;
      var status=classificationFromLabel(statusCell.textContent||'');
      row.dataset.classificationStatus=status;
      row.classList.remove('bank-row-unallocated','bank-row-manual','bank-row-auto');
      if(status==='MANUAL') row.classList.add('bank-row-manual');
      else if(status==='AUTO') row.classList.add('bank-row-auto');
      else row.classList.add('bank-row-unallocated');
      statusCell.remove();
    });
    statusHeader.remove();
  }

  function initBankRegisterFilters(){
    initClassificationPresentation();
    var form=document.querySelector('form.bank-controls-filter');
    if(!form||form.dataset.autoFiltersReady==='1') return;
    form.dataset.autoFiltersReady='1';
    form.setAttribute('autocomplete','off');
    Array.from(form.querySelectorAll('button[type="submit"]')).forEach(function(btn){if((btn.textContent||'').trim()==='Применить')btn.remove();});
    Array.from(form.querySelectorAll('a.btn')).forEach(function(link){if(/сброс/i.test(link.textContent||''))link.remove();});
    var right=document.querySelector('.bank-controls-right');
    if(right)Array.from(right.querySelectorAll('a.btn')).forEach(function(link){if(/сброс/i.test(link.textContent||''))link.remove();});

    var pending=null,lastSignature=null;
    var submitNow=function(){
      if(pending){clearTimeout(pending);pending=null;}
      var params=new URLSearchParams(new FormData(form));
      Array.from(params.keys()).forEach(function(key){if((params.get(key)||'').trim()==='')params.delete(key);});
      params.delete('tx_page');
      var signature=params.toString(),current=new URLSearchParams(window.location.search);
      current.delete('tx_page');
      Array.from(current.keys()).forEach(function(key){if((current.get(key)||'').trim()==='')current.delete(key);});
      if(signature===current.toString()||signature===lastSignature)return;
      lastSignature=signature;window.location.assign(window.location.pathname+(signature?'?'+signature:''));
    };
    ['date_from','date_to','classification_status'].forEach(function(name){var field=form.querySelector('[name="'+name+'"]');if(field)field.addEventListener('change',submitNow);});
    var search=form.querySelector('[name="q"]');
    if(search){search.addEventListener('input',function(){if(pending)clearTimeout(pending);pending=setTimeout(submitNow,350);});search.addEventListener('search',submitNow);search.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();submitNow();}});}
    form.addEventListener('submit',function(event){event.preventDefault();submitNow();});
  }

  function parseMoney(value){
    var n=String(value||'').replace(/\s+/g,'').replace(',','.');
    return /^\d+(?:\.\d{0,2})?$/.test(n)?Math.round(parseFloat(n||'0')*100):NaN;
  }
  function formatCents(cents){return (cents/100).toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₽';}
  function initSettlementForm(root){
    var form=root.querySelector('[data-bank-settlement-form]');if(!form)return;
    var available=parseMoney(form.dataset.operationRemaining||'0');
    var checks=Array.from(form.querySelectorAll('.js-settlement-check'));
    var totalNode=form.querySelector('[data-settlement-total]'),errorNode=form.querySelector('[data-settlement-error]'),submit=form.querySelector('[data-settlement-submit]');
    function validate(){
      var total=0,error='';
      checks.forEach(function(check){
        var row=check.closest('[data-settlement-invoice-row]'),id=row.querySelector('input[name="invoice_id[]"]'),amount=row.querySelector('.js-settlement-amount');
        id.disabled=!check.checked;amount.disabled=!check.checked;
        if(!check.checked)return;
        var cents=parseMoney(amount.value),max=parseMoney(amount.dataset.max||'0');
        if(!Number.isFinite(cents)||cents<=0)error='Укажите сумму больше нуля для каждого выбранного счёта.';
        else if(cents>max)error='Сумма распределения не может превышать остаток выбранного счёта.';
        else total+=cents;
      });
      if(!error&&total>available)error='Выбранная сумма превышает остаток банковской операции.';
      if(totalNode)totalNode.textContent=formatCents(total);
      if(errorNode){errorNode.textContent=error;errorNode.classList.toggle('is-hidden',!error);}
      if(submit)submit.disabled=!!error||total<=0;
    }
    checks.forEach(function(check){check.addEventListener('change',validate);var row=check.closest('[data-settlement-invoice-row]');row.querySelector('.js-settlement-amount').addEventListener('input',validate);});
    form.addEventListener('submit',function(event){validate();if(submit&&submit.disabled)event.preventDefault();});
    validate();
  }

  function settlementUrl(row){
    var classify=row.dataset.classifyUrl||'';
    return classify.replace(/\/classify(?:\?.*)?$/,'/settlement');
  }
  async function tryOpenSettlement(row){
    if(row.dataset.companyFunded==='1')return false;
    var url=settlementUrl(row);if(!url)return false;
    var response=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    if(response.status===404)return false;
    var html=await response.text();
    var body=document.querySelector('#bank-invoice-settlement-modal [data-bank-settlement-body]');
    body.innerHTML=response.ok?html:'<div class="modal-body"><div class="form-alert alert-error">'+html+'</div></div>';
    initSettlementForm(body);
    window.openModal('bank-invoice-settlement-modal');
    return true;
  }

  function initSettlementDblclick(){
    if(document.documentElement.dataset.bankSettlementDblclickReady==='1')return;
    document.documentElement.dataset.bankSettlementDblclickReady='1';
    document.addEventListener('dblclick',function(event){
      var row=event.target.closest('.bank-transactions-table tbody tr[data-tx-id]');if(!row)return;
      if(row.dataset.settlementGenericPass==='1'){delete row.dataset.settlementGenericPass;return;}
      event.preventDefault();event.stopImmediatePropagation();
      tryOpenSettlement(row).then(function(opened){
        if(opened)return;
        row.dataset.settlementGenericPass='1';
        row.dispatchEvent(new MouseEvent('dblclick',{bubbles:true,cancelable:true,view:window}));
      }).catch(function(){
        row.dataset.settlementGenericPass='1';
        row.dispatchEvent(new MouseEvent('dblclick',{bubbles:true,cancelable:true,view:window}));
      });
    },true);
  }

  function init(){initBankRegisterFilters();initSettlementDblclick();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
HTML;

$html = str_replace('</head>', $style . "\n</head>", $html);
$html = str_replace('</body>', $settlementModal . "\n" . $script . "\n</body>", $html);
echo $html;
