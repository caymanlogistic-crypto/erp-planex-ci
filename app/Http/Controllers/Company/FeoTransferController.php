<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\FeoRequestService;
use App\Service\FeoTransferService;
use App\Service\FeoPlacementAuthorService;
use App\Service\YandexGeocodingService;
use App\Service\MaxEventNotificationService;
use PDO;
use RuntimeException;

final class FeoTransferController
{
    private FeoTransferService $service;
    public function __construct(private readonly array $config, private readonly Database $db) { $this->service=new FeoTransferService(); }

    public function index(): void { $this->page(false); }
    public function coordinates(): void { $this->page(true); }

    private function page(bool $coordinatesOnly): void
    {
        $pageTitle=$coordinatesOnly?'Определение координат':'Перемещение заявок';
        $pageContext='Заявки ФЭО › '.$pageTitle;
        $batches=[]; $batchIndexItems=[]; $dbError=null; $keyConfigured=false;
        $page=max(1,min(100000,(int)($_GET['page']??1)));
        $isOwner=AccessRuntime::current()->isSystemOwner();
        try {
            [$company,$pdo]=$this->context();
            if (!$this->service->ready($pdo)) throw new RuntimeException('Модуль ожидает обновления базы компании (139).');
            if (!$coordinatesOnly) {
                $batches=$this->service->batches($pdo,$page);
                $batchIndexItems=$this->service->batchIndexItems($pdo,$batches);
            }
            $keyConfigured=$this->apiKey($pdo)!=='';
        } catch (\Throwable $e) { $dbError=$e->getMessage(); }
        $config=$this->config; $db=$this->db;
        $topbarCrumbs=[['label'=>'Заявки ФЭО','url'=>null],['label'=>$pageTitle,'url'=>null]];
        ob_start(); require base_path('app/View/pages/company_feo_transfers.php'); $content=ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function geocode(): void
    {
        $this->respond(function (): array {
            [$company,$pdo]=$this->context();
            $recent=(int)($_SESSION['geo_last_request']??0);
            if ($recent>time()-1) throw new RuntimeException('Подождите секунду перед следующим поиском.');
            $_SESSION['geo_last_request']=time();
            $result=(new YandexGeocodingService($this->apiKey($pdo)))->geocode((string)($_POST['address']??''));
            $token=$this->remember(['kind'=>'geocode','company_id'=>(int)$company['id'],'result'=>$result]);
            return ['result'=>$result,'geo_token'=>$token];
        });
    }

    public function preview(): void
    {
        $this->respond(function (): array {
            [$company,$pdo]=$this->context();
            $parsed=FeoTransferService::parseIds((string)($_POST['request_ids']??''));
            $mode=(string)($_POST['mode']??''); $geo=null;
            if ($mode==='address') $geo=$this->record((string)($_POST['geo_token']??''),(int)$company['id'],'geocode')['result'];
            $preview=$this->service->preview($pdo,$parsed['ids'],$mode,trim((string)($_POST['source_request_id']??'')),$geo);
            $token=$this->remember(['kind'=>'transfer','company_id'=>(int)$company['id'],'preview'=>$preview,
                'name'=>trim((string)($_POST['name']??'')),'comment'=>trim((string)($_POST['comment']??''))]);
            return ['preview'=>$preview,'preview_token'=>$token,'duplicates'=>$parsed['duplicates']];
        });
    }

    public function create(): void
    {
        $this->respond(function (): array {
            [$company,$pdo]=$this->context();
            $token=(string)($_POST['preview_token']??'');
            $record=$this->record($token,(int)$company['id'],'transfer');
            $actor=$this->actor($pdo,(int)$company['id']);
            $events=new MaxEventNotificationService($pdo); $deliveryId=null;
            $result=$this->service->create($pdo,$record['preview'],$actor,hash('sha256',$token),$record['name'],$record['comment'],
                function(array $created) use ($events,&$deliveryId,$company,$actor,$record): void {
                    $deliveryId=$events->enqueue('feo.transfer.created',(string)$created['batch_id'],$this->eventValues($created,$record['name'],$record['comment'],$company,$actor),$actor['created_by_user_id']);
                });
            // Keep the short-lived server record so a network retry returns the same batch.
            if ($deliveryId===null && !empty($result['already_applied'])) $deliveryId=$this->deliveryId($pdo,'feo.transfer.created',$result['batch_id']);
            return $result+['notification'=>$this->dispatch($events,$deliveryId)];
        });
    }

    public function revert(string $id): void
    {
        $this->respond(function () use ($id): array {
            [$company,$pdo]=$this->context(); $actor=$this->actor($pdo,(int)$company['id']);
            $events=new MaxEventNotificationService($pdo); $deliveryId=null;
            $result=$this->service->revert($pdo,$this->id($id),$actor,function(array $reverted) use ($events,&$deliveryId,$company,$actor): void {
                $values=$this->eventValues($reverted,$reverted['name'],'',$company,$actor);
                $values['quality']='Восстановлены исходные координаты каждой заявки; точность не подтверждена';
                $values['address']='Исходный адрес каждой заявки';
                $deliveryId=$events->enqueue('feo.transfer.reverted',(string)$reverted['batch_id'],$values,$actor['created_by_user_id']);
            });
            if ($deliveryId===null && !empty($result['already_reverted'])) $deliveryId=$this->deliveryId($pdo,'feo.transfer.reverted',$result['batch_id']);
            return $result+['notification'=>$this->dispatch($events,$deliveryId)];
        });
    }

    public function detail(string $id): void
    {
        $this->respond(function () use ($id): array {
            [, $pdo]=$this->context(); $batchId=$this->id($id);
            $q=$pdo->prepare('SELECT * FROM feo_transfer_batches WHERE id=?'); $q->execute([$batchId]);
            $batch=$q->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Блок не найден.');
            $q=$pdo->prepare('SELECT operation,status,created_at FROM feo_transfer_outbox WHERE batch_id=? ORDER BY id'); $q->execute([$batchId]);
            return ['batch'=>$batch,'items'=>$this->service->items($pdo,$batchId),'legacy'=>$q->fetchAll(PDO::FETCH_ASSOC)];
        });
    }

    public function export(string $id): void
    {
        try {
            [$company,$pdo]=$this->context();
            $q=$pdo->prepare('SELECT id,operation,payload_json,status FROM feo_transfer_outbox WHERE batch_id=? ORDER BY id'); $q->execute([$this->id($id)]);
            $operations=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!$operations) throw new RuntimeException('Блок не найден.');
            foreach ($operations as &$row) { $row['payload']=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR); unset($row['payload_json']); } unset($row);
            header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="feo-transfer-'.(int)$id.'.json"');
            header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
            echo json_encode(['company_id'=>(int)$company['id'],'operations'=>$operations],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }

    private function context(): array
    {
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->companyId()) throw new RuntimeException('Не удалось подтвердить доступ к компании.');
        $requests=new FeoRequestService($this->config,$this->db);
        $company=$requests->loadCompany($access->companyId());
        if (!$company || $company['status']!=='active') throw new RuntimeException('Компания недоступна.');
        return [$company,$requests->localPdo($company)];
    }
    private function actor(PDO $pdo, int $companyId): array
    {
        $actor=FeoPlacementAuthorService::creatorFromSession($_SESSION);
        return (new FeoPlacementAuthorService($this->db->connection()))->decorate($pdo,$companyId,[$actor])[0];
    }
    private function apiKey(PDO $pdo): string
    {
        $value=$pdo->query('SELECT api_key_encrypted FROM geocoding_settings WHERE id=1')->fetchColumn();
        return $value ? (string)cryptoDecrypt($value) : trim((string)getenv('YANDEX_GEOCODER_API_KEY'));
    }
    private function remember(array $record): string
    {
        $records=$_SESSION['feo_transfer_previews']??[];
        foreach ($records as $key=>$value) if (($value['created_at']??0)<time()-1800) unset($records[$key]);
        while (count($records)>=12) array_shift($records);
        $token=bin2hex(random_bytes(24));
        $records[$token]=$record+['created_at'=>time(),'account_id'=>(int)($_SESSION['account_id']??0)];
        $_SESSION['feo_transfer_previews']=$records;
        return $token;
    }
    private function record(string $token, int $companyId, string $kind): array
    {
        $r=$_SESSION['feo_transfer_previews'][$token]??null;
        if (!is_array($r) || $r['kind']!==$kind || $r['company_id']!==$companyId || $r['account_id']!==(int)($_SESSION['account_id']??0) || $r['created_at']<time()-1800) throw new RuntimeException('Проверка устарела. Выполните её заново.');
        return $r;
    }
    private function eventValues(array $result, string $name, string $comment, array $company, array $actor): array
    {
        $items=is_array($result['items']??null)?$result['items']:[];
        $requestIds=array_values(array_map(static fn(array $item):string=>(string)($item['request_number']??''),$items));
        $visibleIds=array_slice($requestIds,0,120);
        $requestList=implode(', ',$visibleIds);
        if (count($requestIds)>count($visibleIds)) $requestList.='; ещё '.(count($requestIds)-count($visibleIds));
        $changes=[];
        foreach (array_slice($items,0,15) as $item) {
            $before=$this->shortAddress((string)($item['before']['mno_adres_pogruzki']??''));
            $after=$this->shortAddress((string)($item['after']['mno_adres_pogruzki']??''));
            $changes[]='№'.(string)($item['request_number']??'').' — '.$before.' → '.$after;
        }
        if (count($items)>count($changes)) $changes[]='… ещё '.(count($items)-count($changes)).' заявок; полный состав сохранён в блоке.';
        return ['batch_id'=>(string)$result['batch_id'],'batch_name'=>$name?:'Перемещение заявок','request_count'=>(string)$result['count'],
            'request_ids'=>$requestList?:'—','changes'=>$changes?implode("\n",$changes):'—',
            'address'=>mb_substr($result['destination']['address'],0,1000),'quality'=>$result['destination']['quality'],
            'author'=>$actor['created_by_display_name'],'company'=>$company['name'],'created_at'=>date('d.m.Y H:i'),
            'comment'=>mb_substr($comment,0,800)];
    }
    private function shortAddress(string $address): string
    {
        $address=trim(preg_replace('/\s+/u',' ',$address)??$address);
        return $address===''?'без адреса':mb_substr($address,0,70).(mb_strlen($address)>70?'…':'');
    }
    private function dispatch(MaxEventNotificationService $events, ?int $deliveryId): string
    {
        if ($deliveryId===null) return 'disabled';
        try { return $events->dispatch($deliveryId)['status']; } catch (\Throwable) { return 'failed'; }
    }
    private function deliveryId(PDO $pdo, string $event, int $batchId): ?int
    {
        if (!(new MaxEventNotificationService($pdo))->ready()) return null;
        $q=$pdo->prepare('SELECT id FROM max_event_deliveries WHERE event_key=? AND entity_key=?'); $q->execute([$event,(string)$batchId]);
        $id=$q->fetchColumn(); return $id!==false?(int)$id:null;
    }
    private function id(string $id): int { if (!ctype_digit($id) || (int)$id<=0) throw new RuntimeException('Некорректный номер блока.'); return (int)$id; }
    private function respond(callable $action): void
    {
        try { jsonResponse(['success'=>true]+$action()); }
        catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }
}
