<?php
namespace App\Service;
use PDO;
require_once __DIR__.'/FinanceEmployeeCompanyFundedExpenseService.php';
trait FinanceMatchingRuleRegisterTrait{
 public static function fetchBankRegister(PDO $pdo,array $f=[]):array{
  $dateFrom=trim((string)($f['date_from']??''));$dateFrom=$dateFrom===''?null:$dateFrom;
  $dateTo=trim((string)($f['date_to']??''));$dateTo=$dateTo===''?null:$dateTo;
  $all=[];$page=1;$chunk=500;
  do{$batch=BankFinanceService::getTransactions($pdo,null,$page,$chunk,$dateFrom,$dateTo);$all=array_merge($all,$batch['data']);$page++;}while(count($all)<(int)$batch['total']);
  $settlementStatuses=class_exists(FinanceSettlementStateService::class)?FinanceSettlementStateService::effectiveBankStatuses($pdo):[];
  $pendingClientReceipts=class_exists(FinanceSettlementStateService::class)?FinanceSettlementStateService::unallocatedClientReceiptIds($pdo,$settlementStatuses):[];
  $employeeAttributions=FinanceEmployeeCompanyFundedExpenseService::bankAttributions($pdo);
  $cfu=[];foreach(self::fetchCashFlowCenters($pdo,false) as $x)$cfu[(int)$x['id']]=$x['name'];$dds=[];foreach(FinanceDdsCategoryService::fetchCategories($pdo) as $x)$dds[(int)$x['id']]=$x;
  $status=strtoupper(trim((string)($f['classification_status']??'')));$q=self::lower(trim((string)($f['search']??'')));$rows=[];
  foreach($all as $tx){
   $txId=(int)($tx['id']??0);
   if(isset($settlementStatuses[$txId]))$tx['classification_status']=$settlementStatuses[$txId];
   if(isset($pendingClientReceipts[$txId]))$tx['classification_status']='UNALLOCATED';
   $attribution=$employeeAttributions[$txId]??null;
   if($attribution!==null){$tx['company_funded_attribution']=$attribution;$tx['classification_status']='MANUAL';}
   if($status!==''&&strtoupper((string)($tx['classification_status']??'UNALLOCATED'))!==$status)continue;
   $cfuName=$cfu[(int)($tx['cash_flow_center_id']??0)]??'';$d=$dds[(int)($tx['dds_category_id']??0)]??[];
   $hay=self::lower(implode(' ',[$tx['document_number']??'',$tx['counterparty_name']??'',$tx['counterparty_inn']??'',$tx['purpose']??'',$cfuName,$d['name']??'',$attribution['employee_name']??'',$attribution!==null?'Оплачено компанией Взаиморасчёты с сотрудниками':'']));
   if($q!==''&&!str_contains($hay,$q))continue;
   $tx['cash_flow_center_name']=$cfuName;$tx['dds_category_name']=$d['name']??'';$tx['dds_category_code']=$d['code']??'';$rows[]=$tx;
  }
  $per=max(1,min(500,(int)($f['per_page']??100)));$current=max(1,(int)($f['page']??1));$total=count($rows);$pages=max(1,(int)ceil($total/max(1,$per)));if($current>$pages)$current=$pages;return ['data'=>array_slice($rows,($current-1)*$per,$per),'total'=>$total,'page'=>$current,'per_page'=>$per,'pages'=>$pages];
 }
 public static function fetchBankTransactionForClassification(PDO $pdo,int $id):?array{
  $page=1;do{$batch=BankFinanceService::getTransactions($pdo,null,$page,500);foreach($batch['data'] as $tx)if((int)$tx['id']===$id)return $tx;$page++;}while(($page-1)*500<(int)$batch['total']);return null;
 }
}
