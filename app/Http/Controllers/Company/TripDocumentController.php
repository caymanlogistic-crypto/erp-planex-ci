<?php
declare(strict_types=1);
namespace App\Http\Controllers\Company;
use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\TripDocumentCollectionService;
use App\Service\TripDocumentData;
use App\Service\RegulatedDataScopeService;
use PDO;

final class TripDocumentController
{
    public function __construct(private readonly array $config,private readonly Database $db) {}

    private function context(): array
    {
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->companyId() || !$access->membershipId()) throw new \DomainException('Войдите в компанию.');
        $central=$this->db->connection();
        $q=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$q->execute([$access->companyId()]);
        $company=$q->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new \DomainException('Компания недоступна.');
        $tenant=(new Database(companyDatabaseConfig($this->config,$company)))->connection();
        return [$company,new TripDocumentCollectionService($central,$tenant,(int)$company['id']),(int)$access->membershipId()];
    }

    public function requestForm(): void {$this->page('request');}
    public function index(): void {$this->page('collection');}
    public function show(string $id): void {$this->page('detail',(int)$id);}

    private function page(string $mode,int $id=0): void
    {
        header('Cache-Control: no-store');
        $error=null;$request=null;$rows=[];$trips=[];$entities=[];$cards=[];$incoming=[];$outgoing=[];$history=[];$assistant=false;$mailboxes=[];$ambiguous=[];$executors=[];
        try {
            [$company,$service,$actor]=$this->context();
            if (!$service->ready()) throw new \DomainException('Сбор документов ожидает подключения. Обратитесь к руководителю.');
            $assistant=$actor===(int)$service->assistant()['membership_id'];
            if ($mode==='request') {
                $q=$service->central->prepare('SELECT id,name FROM employee_mail_legal_entities WHERE company_id=? ORDER BY id');$q->execute([$company['id']]);$entities=$q->fetchAll(PDO::FETCH_ASSOC);
                {
                    $scope=RegulatedDataScopeService::condition('waste_trip','t.id','t.created_by_membership_id');
                    $q=$service->tenant->prepare("SELECT t.id,t.business_id number,t.comment,COALESCE(ct.name,'') contractor_name,COALESCE(NULLIF(GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / '),''),fd.full_name,'') driver_names FROM waste_trips t LEFT JOIN crews c ON c.id=t.route_executor_id AND c.deleted_at IS NULL LEFT JOIN contractors ct ON ct.id=c.contractor_id AND ct.deleted_at IS NULL LEFT JOIN crew_drivers cd ON cd.crew_id=c.id LEFT JOIN drivers d ON d.id=cd.driver_id AND d.deleted_at IS NULL LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id AND dvb.deleted_at IS NULL LEFT JOIN drivers fd ON fd.id=dvb.driver_id AND fd.deleted_at IS NULL WHERE t.deleted_at IS NULL AND t.status NOT IN ('cancelled','completed','archived') AND {$scope['sql']} GROUP BY t.id,t.business_id,t.comment,ct.name,fd.full_name ORDER BY t.id DESC LIMIT 200");
                    $q->execute($scope['params']);
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $trip) $trips[]=['type'=>'waste','label'=>TripDocumentData::tripLabel($trip)]+$trip;
                }
            } elseif ($mode==='collection') $rows=$service->listing($actor);
            else {
                $request=$service->request($id,$actor);
                $_SESSION['document_collection_return']=['request_id'=>$id,'company_id'=>(int)$company['id'],'membership_id'=>$actor];
                $trip=$service->data->trip($request['trip_type'],(int)$request['trip_id']);
                $cards=$service->data->cards($trip);
                $completeness=$service->completeness($request);
                if ($assistant) $executors=$service->executorChoices($id,$actor);
                foreach (['incoming'=>'SELECT * FROM trip_document_incoming WHERE request_id=? ORDER BY id DESC','outgoing'=>'SELECT * FROM trip_document_mail WHERE request_id=? ORDER BY id DESC','history'=>'SELECT * FROM trip_document_events WHERE request_id=? ORDER BY id DESC'] as $variable=>$sql) {
                    $q=$service->tenant->prepare($sql);$q->execute([$id]);${$variable}=$q->fetchAll(PDO::FETCH_ASSOC);
                }
                if ($assistant) {
                    $q=$service->tenant->query('SELECT id,sender,subject,received_at,candidate_request_ids FROM trip_document_incoming WHERE request_id IS NULL ORDER BY id DESC');
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $mail) {
                        if (in_array($id,json_decode($mail['candidate_request_ids'],true),true)) $ambiguous[]=$mail;
                    }
                }
                $q=$service->tenant->prepare('SELECT email,last_checked_at,error_message FROM trip_document_mailboxes WHERE account_id IN (?,?) AND legal_entity_id=?');
                $q->execute([$request['assistant_account_id'],$request['responsible_account_id'],$request['legal_entity_id']]);$mailboxes=$q->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\DomainException $e) {$error=$e->getMessage();}
        catch (\Throwable) {$error='Не удалось загрузить документы. Попробуйте обновить страницу.';}
        $pageTitle=$mode==='request'?'Запросить документы':'Сбор документов';
        $pageContext='Рейсы › '.$pageTitle;
        $topbarCrumbs=[['label'=>'Рейсы','url'=>null],['label'=>$pageTitle,'url'=>null]];
        $config=$this->config;$db=$this->db;
        ob_start();require base_path('app/View/pages/company_trip_documents.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
    }

    public function trip(): void
    {
        $this->json(function(TripDocumentCollectionService $service,int $actor):array {
            $type=(string)($_GET['type']??'');$id=(int)($_GET['id']??0);
            $table=\App\Service\TripDocumentData::tripTable($type);
            if (!RegulatedDataScopeService::canAccess($service->tenant,'waste_trip',$table,$id)) throw new \DomainException('Рейс недоступен.');
            $trip=$service->data->trip($type,$id);$cards=$service->data->cards($trip);
            return ['number'=>$trip['number'],'responsible'=>$trip['responsible']['full_name'],'loading_dates'=>$trip['loading_dates'],'contacts'=>$cards['contacts']];
        });
    }

    public function preview(): void
    {
        verifyCsrfRequest();
        $this->json(fn(TripDocumentCollectionService $s,int $actor):array=>$s->previewRequest($actor,array_replace($_POST,requestJsonBody())));
    }

    public function send(): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor):array {
            $input=array_replace($_POST,requestJsonBody());$mail=$s->sendPreview($actor,(string)($input['token']??''));
            $q=$s->tenant->prepare('SELECT request_id FROM trip_document_mail WHERE id=?');$q->execute([$mail]);
            return ['url'=>app_url('/company/documents/collection/'.(int)$q->fetchColumn())];
        });
    }

    public function review(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor) use($id):array {
            $s->scheduleReview((int)$id,$actor,(string)($_POST['next_check_at']??''));return [];
        });
    }

    public function previewAction(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor) use($id):array {
            return match((string)($_POST['kind']??'')) {
                'reminder'=>$s->previewReminder((int)$id,$actor,$_POST),
                'retry'=>$s->previewRetry((int)$id,$actor,(int)($_POST['mail_id']??0)),
                'minimum','full'=>$s->previewPackage((int)$id,$actor,(string)$_POST['kind']),
                default=>throw new \DomainException('Неизвестное действие.')
            };
        });
    }

    public function sendAction(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor) use($id):array {
            $s->request((int)$id,$actor,true);
            $q=$s->tenant->prepare('SELECT token FROM trip_document_previews WHERE token=? AND request_id=? AND membership_id=?');$q->execute([(string)($_POST['token']??''),(int)$id,$actor]);
            $token=$q->fetchColumn();if (!$token) throw new \DomainException('Предпросмотр не найден.');
            $s->sendPreview($actor,$token);return ['url'=>app_url('/company/documents/collection/'.(int)$id)];
        });
    }

    public function bind(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor) use($id):array {
            $s->bindIncoming((int)$id,$actor,(int)($_POST['incoming_id']??0));return [];
        });
    }

    public function executor(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor)use($id):array {
            $s->assignExecutor((int)$id,$actor,(int)($_POST['crew_id']??0),(int)($_POST['version']??0));return [];
        });
    }

    public function tax(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor)use($id):array{$s->saveTax((int)$id,$actor,$_POST);return [];});
    }

    public function resolve(string $id): void
    {
        verifyCsrfRequest();require_once base_path('app/Service/TripDocumentWorker.php');
        $this->json(function(TripDocumentCollectionService $s,int $actor)use($id):array {
            (new \App\Service\TripDocumentWorker($s))->resolveUncertain((int)$id,$actor,(int)($_POST['mail_id']??0),(string)($_POST['outcome']??''),(string)($_POST['note']??''));return [];
        });
    }

    public function import(string $id): void
    {
        verifyCsrfRequest();
        $this->json(function(TripDocumentCollectionService $s,int $actor) use($id):array {
            $document=$s->importAttachment((int)$id,$actor,(int)($_POST['incoming_id']??0),(int)($_POST['index']??-1),(string)($_POST['entity_type']??''),(int)($_POST['entity_id']??0),(string)($_POST['type_code']??''));
            return ['document_id'=>$document];
        });
    }

    public function download(string $id,string $incoming,string $index): void
    { $this->file($id,$incoming,$index,false); }

    public function outgoingDownload(string $id,string $mail,string $index): void
    { $this->file($id,$mail,$index,true); }

    private function file(string $id,string $mail,string $index,bool $outgoing): void
    {
        try {
            [, $s,$actor]=$this->context();$file=$outgoing?$s->outgoingFile((int)$id,$actor,(int)$mail,(int)$index):$s->incomingFile((int)$id,$actor,(int)$mail,(int)$index);
            header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(\App\Service\DocumentService::safeDownloadName($file['name'])));
            header('Content-Length: '.filesize($file['path']));readfile($file['path']);
        } catch (\Throwable) {http_response_code(404);echo 'Вложение недоступно.';}
    }

    private function json(callable $action): void
    {
        header('Cache-Control: no-store');
        try {[, $service,$actor]=$this->context();if (!$service->ready()) throw new \DomainException('Сбор документов ещё не подключён.');jsonResponse(['ok'=>true]+$action($service,$actor));}
        catch (\DomainException|\InvalidArgumentException $e) {jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);}
        catch (\Throwable) {jsonResponse(['ok'=>false,'message'=>'Операция не завершена. Введённые данные сохранены в форме. Попробуйте ещё раз.'],500);}
    }
}
