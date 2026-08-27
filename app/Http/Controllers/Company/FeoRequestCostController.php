<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FeoPlacementInputParser;
use App\Service\FeoRequestCostService;
use App\Service\FeoRequestService;

final class FeoRequestCostController
{
    private FeoPlacementInputParser $parser;
    private FeoRequestCostService $costs;
    private FeoRequestService $requests;

    public function __construct(private readonly array $config, private readonly Database $db)
    {
        $this->parser=new FeoPlacementInputParser();
        $this->costs=new FeoRequestCostService();
        $this->requests=new FeoRequestService($config,$db);
    }

    public function index(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        $pageTitle='Стоимость заявок';
        $pageContext='Заявки ФЭО › Стоимость заявок';
        $company=null;$dbError=null;$blocks=[];$integrity=['block_count'=>0,'request_count'=>0,'has_issues'=>false];
        $successFlash=$_SESSION['flash_success']??null; $errorFlash=$_SESSION['flash_error']??null;
        unset($_SESSION['flash_success'],$_SESSION['flash_error']);
        try {
            [$company,$pdo]=$this->companyContext();
            if (!$this->costs->moduleReady($pdo)) throw new \RuntimeException('Модуль «Стоимость заявок» ещё не инициализирован в базе компании.');
            $state=$this->costs->pageState($pdo); $blocks=$state['blocks']; $integrity=$state['integrity'];
        } catch (\Throwable $e) { $dbError=$e->getMessage(); }
        if (is_array($company)) $topbarCrumbs=[
            ['label'=>mb_strtoupper((string)($company['name']??'Компания')),'url'=>app_url('/company/dashboard')],
            ['label'=>'Заявки ФЭО','url'=>app_url('/company/feo/requests')],
            ['label'=>'Стоимость заявок','url'=>null],
        ];
        $config=$this->config; $db=$this->db;
        ob_start(); require base_path('app/View/pages/company_feo_request_costs.php'); $content=ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function validate(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try {
            $parsed=$this->parser->parseManual((string)($_POST['request_ids']??''));
            [, $pdo]=$this->readyContext();
            $result=$this->costs->validateSelection($pdo,$parsed['ids']);
            $result['duplicates']=$parsed['duplicates'];
            $result['valid']=$result['valid'] && $parsed['duplicates']===[];
            jsonResponse(['success'=>true]+$result);
        } catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    public function analyzeCreate(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try {
            [$company,$pdo]=$this->readyContext(); $this->cleanupPreviews((int)$company['id']);
            $parsed=$this->parser->parseManual((string)($_POST['request_ids']??''));
            if ($parsed['duplicates']!==[]) throw new \RuntimeException('Уберите повторяющиеся заявки: '.implode(', ',$parsed['duplicates']).'.');
            $type=(string)($_POST['pricing_type']??'agreement');
            $manual=(string)($_POST['total_cost']??'');
            $preview=$this->costs->previewCreate($pdo,$parsed['ids'],$type,$manual);
            $token=bin2hex(random_bytes(24));
            $_SESSION['feo_request_cost_previews'][$token]=[
                'kind'=>'create','company_id'=>(int)$company['id'],'created_at'=>time(),'ids'=>$parsed['ids'],
                'pricing_type'=>$preview['pricing_type'],'manual_total'=>$preview['pricing_type']==='agreement'?$manual:null,
                'comment'=>trim((string)($_POST['comment']??'')),'selection_fingerprint'=>$preview['selection_fingerprint'],
            ];
            jsonResponse(['success'=>true,'token'=>$token,'preview'=>$preview]);
        } catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    public function commitCreate(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try {
            [$company,$pdo]=$this->readyContext();
            $token=trim((string)($_POST['preview_token']??'')); $record=$this->previewRecord($token,(int)$company['id'],'create');
            $result=$this->costs->createBlock($pdo,[
                'ids'=>$record['ids'],'pricing_type'=>$record['pricing_type'],'manual_total'=>$record['manual_total'],'comment'=>$record['comment'],
                'created_by_user_id'=>(int)($_SESSION['user_id']??0),'created_by_role'=>(string)($_SESSION['role_code']??''),
            ],(string)$record['selection_fingerprint']);
            unset($_SESSION['feo_request_cost_previews'][$token]);
            $_SESSION['flash_success']=sprintf('Блок стоимости #%d сохранён: %d заявок, %s кг, %s ₽.',(int)$result['block_id'],(int)$result['request_count'],$this->number((float)$result['snapshot_total_mass_kg'],3),$this->number((float)$result['total_cost'],2));
            jsonResponse(['success'=>true]+$result);
        } catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    public function detail(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try { [, $pdo]=$this->readyContext(); $block=$this->costs->block($pdo,(int)$id); if (!$block) { jsonResponse(['success'=>false,'message'=>'Блок стоимости не найден.'],404); return; } jsonResponse(['success'=>true,'block'=>$block]); }
        catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    public function search(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try { $parsed=$this->parser->parseManual((string)($_GET['request_ids']??'')); [, $pdo]=$this->readyContext(); jsonResponse(['success'=>true,'duplicates'=>$parsed['duplicates'],'results'=>$this->costs->searchBlocks($pdo,$parsed['ids'])]); }
        catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    public function analyzeEdit(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try {
            [$company,$pdo]=$this->readyContext(); $this->cleanupPreviews((int)$company['id']);
            $preview=$this->costs->previewEdit($pdo,(int)$id,(string)($_POST['pricing_type']??'agreement'),(string)($_POST['total_cost']??''),(string)($_POST['comment']??''));
            $token=bin2hex(random_bytes(24));
            $_SESSION['feo_request_cost_previews'][$token]=[
                'kind'=>'edit','company_id'=>(int)$company['id'],'created_at'=>time(),'block_id'=>(int)$id,
                'pricing_type'=>$preview['pricing_type'],'manual_total'=>$preview['pricing_type']==='agreement'?(string)($_POST['total_cost']??''):null,
                'comment'=>$preview['comment'],'edit_fingerprint'=>$preview['edit_fingerprint'],
            ];
            jsonResponse(['success'=>true,'token'=>$token,'preview'=>$preview]);
        } catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    public function commitEdit(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try {
            [$company,$pdo]=$this->readyContext(); $token=trim((string)($_POST['preview_token']??'')); $record=$this->previewRecord($token,(int)$company['id'],'edit');
            $result=$this->costs->editBlock($pdo,(int)$record['block_id'],['pricing_type'=>$record['pricing_type'],'manual_total'=>$record['manual_total'],'comment'=>$record['comment']],(string)$record['edit_fingerprint']);
            unset($_SESSION['feo_request_cost_previews'][$token]); $_SESSION['flash_success']='Блок стоимости #'.(int)$record['block_id'].' обновлён.';
            jsonResponse(['success'=>true]+$result);
        } catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    private function companyContext(): array
    {
        $companyId=$this->requests->getCompanyId(); if ($companyId<=0) throw new \RuntimeException('Компания не выбрана.');
        $company=$this->requests->loadCompany($companyId); if (!$company) throw new \RuntimeException('Компания не найдена.');
        if (($company['status']??'')!=='active') throw new \RuntimeException('Компания недоступна.');
        return [$company,$this->requests->localPdo($company)];
    }

    private function readyContext(): array
    {
        [$company,$pdo]=$this->companyContext(); if (!$this->costs->moduleReady($pdo)) throw new \RuntimeException('Модуль «Стоимость заявок» ещё не инициализирован.'); return [$company,$pdo];
    }

    private function previewRecord(string $token,int $companyId,string $kind): array
    {
        $record=$_SESSION['feo_request_cost_previews'][$token]??null;
        if (!is_array($record)||($record['kind']??'')!==$kind||(int)($record['company_id']??0)!==$companyId||(int)($record['created_at']??0)<time()-7200) throw new \RuntimeException('Подтверждение устарело. Выполните операцию ещё раз.');
        return $record;
    }

    private function cleanupPreviews(int $companyId): void
    {
        foreach (($_SESSION['feo_request_cost_previews']??[]) as $token=>$record) if (!is_array($record)||(int)($record['company_id']??0)!==$companyId||(int)($record['created_at']??0)<time()-7200) unset($_SESSION['feo_request_cost_previews'][$token]);
    }

    private function number(float $value,int $decimals): string { return number_format($value,$decimals,',',' '); }
}
