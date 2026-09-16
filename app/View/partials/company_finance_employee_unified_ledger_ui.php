<?php
/**
 * Employee workspace UI glue.
 * The page renders the journal semantically; this partial promotes action buttons
 * and attaches the standard double-click editor to editable payment rows.
 */
?>
<style>
.employee-money-actions{display:none!important}
.employee-report .page-head-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
</style>
<script>
(function(){
    const report=document.querySelector('.employee-report');
    if(!report)return;
    const headActions=report.querySelector('.page-head-right');
    if(!headActions)return;
    const invoiceOpen=document.getElementById('employee-invoice-payment-open');
    const personalOpen=document.getElementById('employee-personal-expense-open');
    if(invoiceOpen){
        invoiceOpen.textContent='Оплатить счёт';
        headActions.appendChild(invoiceOpen);
    }
    if(personalOpen)headActions.appendChild(personalOpen);
    report.dataset.employeeActionMatrix='client-receipt,transfer,invoice-payment,personal-expense,company-funded-expense';
})();
</script>
<?php require base_path('app/View/partials/company_finance_employee_ledger_editor.php'); ?>
<?php require base_path('app/View/partials/company_finance_employee_ledger_editor_polish.php'); ?>
