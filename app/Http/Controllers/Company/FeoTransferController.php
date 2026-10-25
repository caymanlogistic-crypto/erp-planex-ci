<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\FeoRequestService;
use App\Service\FeoTransferService;
use App\Service\FeoPlacementAuthorService;
use App\Service\YandexGeocodingService;
use App\Service\MaxEventNotificationService;
use App\Service\MaxEventCatalog;
use App\Service\FeoTransferTripWarningService;
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
            if ($mode==='address') {
                $geo=$this->record((string)($_POST['geo_token']??''),(int)$company['id'],'geocode')['result'];
                if (trim((string)($_POST['address']??'')) !== (string)($geo['query']??'')) {
                    throw new RuntimeException('Адрес изменился после определения координат. Определите координаты снова.');
                }
            }
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
            $events=new MaxEventNotificationService($pdo); $deliveryIds=[];
            $result=$this->service->create($pdo,$record['preview'],$actor,hash('sha256',$token),$record['name'],$record['comment'],
                function(array $created) use ($pdo,$events,&$deliveryIds,$company,$actor,$record): void {
                    $deliveryIds=$this->enqueueMain($pdo,$events,'feo.transfer.created',$created,$record['name'],$record['comment'],$company,$actor);
                    if ($deliveryIds) {
                        $requestNumbers=array_column($created['items'],'request_number');
                        foreach ((new FeoTransferTripWarningService())->intents($pdo,$requestNumbers) as $intent) {
                            $id=$events->enqueuePrepared('feo.transfer.created',
                                'b'.$created['batch_id'].':route_warning:p'.$intent['part'],
                                $intent['text'],$intent['format'],(int)$actor['created_by_user_id']);
                            if ($id!==null) $deliveryIds[]=$id;
                        }
                    }
                });
            // Keep the short-lived server record so a network retry returns the same batch.
            if (!$deliveryIds && !empty($result['already_applied'])) $deliveryIds=$this->deliveryIds($pdo,'feo.transfer.created',$result['batch_id']);
            return $result+['notification'=>$this->dispatchAll($events,$deliveryIds)];
        });
    }

    public function revert(string $id): void
    {
        $this->respond(function () use ($id): array {
            [$company,$pdo]=$this->context(); $actor=$this->actor($pdo,(int)$company['id']);
            $events=new MaxEventNotificationService($pdo); $deliveryIds=[];
            $result=$this->service->revert($pdo,$this->id($id),$actor,function(array $reverted) use ($pdo,$events,&$deliveryIds,$company,$actor): void {
                $deliveryIds=$this->enqueueMain($pdo,$events,'feo.transfer.reverted',$reverted,$reverted['name'],'',$company,$actor);
            });
            if (!$deliveryIds && !empty($result['already_reverted'])) $deliveryIds=$this->deliveryIds($pdo,'feo.transfer.reverted',$result['batch_id']);
            return $result+['notification'=>$this->dispatchAll($events,$deliveryIds)];
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
            'comment'=>mb_substr($comment,0,800),
            'destination_intro'=>'', 'source_request_id'=>(string)($result['destination']['source_request_id']??''),
            'returned_requests'=>implode("\n",array_map(static fn(array $item): string =>
                (string)($item['request_number']??'').': '.(string)($item['after']['mno_adres_pogruzki']??''),$items))];
    }

    /** Preserve an OWNER-custom MAX template; split the approved default without losing requests. */
    private function enqueueMain(PDO $pdo, MaxEventNotificationService $events, string $key, array $result,
        string $name, string $comment, array $company, array $actor): array
    {
        if (!$events->ready()) return [];
        $q=$pdo->prepare('SELECT message_template,message_format FROM max_event_rules WHERE event_key=? AND is_enabled=1');
        $q->execute([$key]); $rule=$q->fetch(PDO::FETCH_ASSOC);
        if (!$rule) return [];
        $values=$this->eventValues($result,$name,$comment,$company,$actor);
        if ($key==='feo.transfer.reverted') {
            $values['quality']='Восстановлены исходные координаты каждой заявки; точность не подтверждена';
            $values['address']='Исходный адрес каждой заявки';
        }
        $template=MaxEventCatalog::currentTemplate($key,(string)$rule['message_template']);
        $userId=(int)$actor['created_by_user_id'];
        if ($template!==MaxEventCatalog::event($key)['template']) {
            $id=$events->enqueue($key,(string)$result['batch_id'],$values,$userId);
            return $id===null?[]:[$id];
        }
        $format=MaxEventCatalog::formatForTemplate($key,(string)$rule['message_format'],$template);
        $entries=[];
        foreach ($result['items'] as $item) {
            $number=(string)$item['request_number'];
            if ($key==='feo.transfer.created') { $entries[]=$number; continue; }
            $address=trim(preg_replace('/\s+/u',' ',(string)($item['after']['mno_adres_pogruzki']??''))??'');
            // The historic address can be longer than one MAX message. Repeat its ID in each segment.
            do {
                $part=mb_substr($address,0,1000);
                $entries[]=$number.': '.$part;
                $address=mb_substr($address,mb_strlen($part));
            } while ($address!=='');
        }
        $parts=[]; $current=[];
        foreach ($entries as $entry) {
            $candidate=[...$current,$entry];
            try { $text=$this->renderMainPart($key,$template,$format,$values,$candidate); }
            catch (RuntimeException $e) {
                if (!str_contains($e->getMessage(),'4000 символов')) throw $e;
                $text=null;
            }
            if ($current && ($text===null || mb_strlen($text,'UTF-8')>3900)) {
                $parts[]=$current; $current=[$entry];
            } else {
                if ($text===null) throw new RuntimeException('Один адрес не помещается в сообщение MAX.');
                $current=$candidate;
            }
        }
        if ($current) $parts[]=$current;
        $ids=[];
        foreach ($parts as $index=>$part) {
            $text=$this->renderMainPart($key,$template,$format,$values,$part);
            $entityKey=$index===0?(string)$result['batch_id']:'b'.$result['batch_id'].':main:p'.$index;
            $id=$events->enqueuePrepared($key,$entityKey,$text,$format,$userId);
            if ($id!==null) $ids[]=$id;
        }
        if ($key==='feo.transfer.created') {
            $fullAddress=(string)$result['destination']['address'];
            $offset=mb_strlen((string)$values['address']);
            $partNumber=count($parts);
            while ($offset<mb_strlen($fullAddress)) {
                $segment=mb_substr($fullAddress,$offset,1000);
                $continuationValues=$values;
                $continuationValues['address']=$segment;
                $text=MaxEventCatalog::render($key,"ПЕРЕМЕЩЕНИЕ ЗАЯВОК\nАдрес назначения (продолжение):\n**{{address}}**",$format,$continuationValues);
                $id=$events->enqueuePrepared($key,'b'.$result['batch_id'].':main:p'.$partNumber,$text,$format,$userId);
                if ($id!==null) $ids[]=$id;
                $partNumber++;
                $offset+=mb_strlen($segment);
            }
        }
        return $ids;
    }

    private function renderMainPart(string $key, string $template, string $format, array $values, array $entries): string
    {
        $values[$key==='feo.transfer.created'?'request_ids':'returned_requests']=implode($key==='feo.transfer.created'?', ':"\n",$entries);
        return MaxEventCatalog::render($key,$template,$format,$values);
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
    private function dispatchAll(MaxEventNotificationService $events, array $deliveryIds): string
    {
        if (!$deliveryIds) return 'disabled';
        $statuses=array_map(fn(int $id): string => $this->dispatch($events,$id),$deliveryIds);
        if (in_array('failed',$statuses,true)) return 'failed';
        if (in_array('sending',$statuses,true) || in_array('pending',$statuses,true)) return 'sending';
        return 'sent';
    }
    private function deliveryIds(PDO $pdo, string $event, int $batchId): array
    {
        if (!(new MaxEventNotificationService($pdo))->ready()) return [];
        $q=$pdo->prepare("SELECT id FROM max_event_deliveries WHERE event_key=? AND (entity_key=? OR entity_key LIKE ? OR entity_key LIKE ?) ORDER BY id");
        $q->execute([$event,(string)$batchId,'b'.$batchId.':main:p%','b'.$batchId.':route_warning:p%']);
        return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
    private function id(string $id): int { if (!ctype_digit($id) || (int)$id<=0) throw new RuntimeException('Некорректный номер блока.'); return (int)$id; }
    private function respond(callable $action): void
    {
        try { jsonResponse(['success'=>true]+$action()); }
        catch (\Throwable $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
    }
}
