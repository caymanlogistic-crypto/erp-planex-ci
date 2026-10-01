<?php
namespace App\Http\Controllers\Company;
use App\Core\Database;
final class BankFinanceController{
 public function __construct(private readonly array $config,private readonly Database $db){}
 private function action(string $name,array $vars=[]):void{$config=$this->config;$db=$this->db;if($vars){extract($vars,EXTR_SKIP);}require base_path('app/Http/Controllers/Company/BankFinanceActions/'.$name.'.php');}
 public function index():void{$this->action('indexAutoFilters');}
 public function import():void{$this->action('import');}
 public function refreshFromMail():void{$this->action('refreshFromMail');}
 public function applyRules():void{$this->action('applyRules');}
 public function settings():void{$this->action('settings');}
 public function bankStatementSettings():void{$this->action('bankStatementSettings');}
 public function deleteImport():void{$this->action('deleteImport');}
 public function downloadImport(int $id):void{$this->action('downloadImport',['bankImportId'=>$id]);}
 public function reconciliationJson():void{$this->action('reconciliationJson');}
 public function classifyForm(int $id):void{$this->action('classifyForm',['bankTransactionId'=>$id]);}
 public function classifySubmit(int $id):void{$this->action('classifySubmit',['bankTransactionId'=>$id]);}
 public function clearClassification(int $id):void{$this->action('clearClassification',['bankTransactionId'=>$id]);}
 public function settlementForm(int $id):void{$this->action('settlementForm',['bankTransactionId'=>$id]);}
 public function settlementSubmit(int $id):void{$this->action('settlementSubmit',['bankTransactionId'=>$id]);}
}
