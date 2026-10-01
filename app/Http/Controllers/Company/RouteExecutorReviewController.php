<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\AccessControlService;
use App\Service\DocumentMailMime;
use App\Service\DocumentMailTransport;
use App\Service\EmployeeMailProfileService;
use App\Service\MaxEventNotificationService;
use App\Service\MaxEventCatalog;
use App\Service\MaxMessagingService;
use App\Service\RouteExecutorReviewEmail;
use App\Service\RouteExecutorReviewArchive;
use App\Service\RouteExecutorReviewCardService;
use App\Service\RouteExecutorReviewPackage;
use App\Service\RouteExecutorReviewRecipientService;
use App\Service\RegulatedDataScopeService;
use DomainException;
use PDO;
use Throwable;

final class RouteExecutorReviewController
{
    public function __construct(private readonly array $config,private readonly Database $db) {}

    public function trips(): void
    {
        header('Cache-Control: no-store');
        try {
            [,,$tenant]=$this->context();
            $search=trim((string)($_GET['q']??''));
            if (mb_strlen($search)>100) throw new DomainException('Слишком длинный запрос поиска.');
            $scope=RegulatedDataScopeService::condition('waste_trip','t.id','t.created_by_membership_id');
            $sql="SELECT t.business_id,t.comment FROM waste_trips t WHERE t.deleted_at IS NULL AND t.status NOT IN ('cancelled','archived') AND {$scope['sql']}";
            $params=$scope['params'];
            if ($search!=='') {
                $sql.=' AND (CAST(t.business_id AS CHAR) LIKE ? OR t.comment LIKE ?)';
                $term='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],ltrim($search,'#')).'%';
                $params[]=$term;$params[]=$term;
            }
            $sql.=' ORDER BY t.business_id DESC LIMIT 30';
            $q=$tenant->prepare($sql);$q->execute($params);
            $this->json(['ok'=>true,'trips'=>array_map(static fn(array $row):array=>[
                'id'=>(int)$row['business_id'],'label'=>self::tripLabel($row),
            ],$q->fetchAll(PDO::FETCH_ASSOC))]);
        } catch (Throwable $error) { $this->failure($error); }
    }

    private static function tripLabel(array $row): string
    { return '#'.(int)$row['business_id'].' '.(trim((string)($row['comment']??''))?:'Без наименования'); }

    private function selectedTrip(PDO $tenant,int $businessId): string
    {
        if ($businessId<=0) throw new DomainException('Выберите рейс перед отправкой.');
        $scope=RegulatedDataScopeService::condition('waste_trip','t.id','t.created_by_membership_id');
        $q=$tenant->prepare("SELECT t.business_id,t.comment FROM waste_trips t WHERE t.business_id=? AND t.deleted_at IS NULL AND t.status NOT IN ('cancelled','archived') AND {$scope['sql']} LIMIT 1");
        $q->execute(array_merge([$businessId],$scope['params']));
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Выбранный рейс недоступен. Выберите действующий рейс.');
        return self::tripLabel($row);
    }

    public function config(): void
    {
        if (isset($_GET['trip_search'])) $this->trips();
        if (isset($_GET['file_card'],$_GET['file_id'])) $this->downloadCardFile();
        header('Cache-Control: no-store');
        try {
            [$company,$central,$tenant,$actorAccountId,$actorName]=$this->context();
            $this->assertSchema($tenant);
            $settings=(new RouteExecutorReviewRecipientService($central,(int)$company['id']))->read();
            $sender=(new EmployeeMailProfileService($central,(int)$company['id']))->defaultSender($actorAccountId);
            $max=new MaxEventNotificationService($tenant);
            $events=[];
            foreach ($max->rules() as $rule) if (str_starts_with((string)$rule['event_key'],'route_executor.')) {
                $events[$rule['event_key']]=['enabled'=>(bool)$rule['is_enabled'],'chat_config_id'=>(int)($rule['chat_config_id']??0),
                    'template'=>(string)$rule['message_template']];
            }
            $this->json(['ok'=>true,'sender'=>(string)$sender['email'],'author'=>$actorName,'recipients'=>array_values(array_map(
                static fn(array $row):string=>(string)$row['email'],$settings['recipients'])),
                'send_responsible_logist'=>$settings['send_responsible_logist'],'max_events'=>$events]);
        } catch (Throwable $error) { $this->failure($error); }
    }

    public function status(): void
    {
        header('Cache-Control: no-store');
        try {
            [$company,,$tenant]=$this->context();$this->assertSchema($tenant);
            $cardId=$this->cardId((string)($_GET['card_id']??''));
            $card=(new RouteExecutorReviewCardService($tenant,(int)$company['id']))->load($cardId);
            $row=$this->row($tenant,$cardId);
            $this->json(['ok'=>true,'stage'=>$row['stage']??'new','mail_status'=>$row ? ($row['final_mail_status']??$row['partial_mail_status']) : null,
                'card'=>$card ? ['snapshot'=>$card['snapshot'],'files'=>array_map(static fn(array $file):array=>[
                    'id'=>$file['id'],'formId'=>$file['formId'],'name'=>$file['name'],'index'=>$file['index'],
                    'original_name'=>$file['original_name'],'mime'=>$file['mime'],'size'=>$file['size']],$card['files']),
                    'revision'=>(int)$card['revision'],'updated_at'=>$card['updated_at']] : null]);
        } catch (Throwable $error) { $this->failure($error); }
    }

    public function send(): void
    {
        verifyCsrfRequest();header('Cache-Control: no-store');
        $reserved=false;$messageId=null;$tenant=null;$cardId='';$stage='';
        try {
            [$company,$central,$tenant,$accountId,$actorName]=$this->context();$this->assertSchema($tenant);
            $cards=new RouteExecutorReviewCardService($tenant,(int)$company['id']);
            if (isPostTruncated()) throw new DomainException('Размер данных превышает серверный лимит.');
            $sizeError=validateTotalUploadSize();if ($sizeError!=='') throw new DomainException($sizeError);
            $cardId=$this->cardId((string)($_POST['card_id']??''));
            $action=(string)($_POST['action']??'');
            if ($action==='save') $this->saveCard($cards,$cardId,$accountId);
            if ($action==='delete') { $cards->delete($cardId);$this->json(['ok'=>true]); }
            if ($action==='resend') $this->resendCard($cards,$central,$tenant,(int)$company['id'],$accountId,$actorName,$cardId);
            $stage=(string)($_POST['stage']??'');
            if (!in_array($stage,['partial','final'],true)) throw new DomainException('Неизвестный этап отправки.');
            $card=$cards->load($cardId);
            if (!$card) throw new DomainException('Сначала сохраните карточку.');
            if ((int)($_POST['revision']??0)!==(int)$card['revision']) throw new DomainException('Карточка изменена другим сотрудником. Обновите страницу.');
            $snapshot=$card['snapshot'];
            $tripId=(string)($snapshot['trip_id']??'');
            if (!preg_match('/^[1-9][0-9]*$/D',$tripId)) throw new DomainException('Выберите рейс перед отправкой.');
            $tripLabel=$this->selectedTrip($tenant,(int)$tripId);
            $package=RouteExecutorReviewPackage::inspect($snapshot,$cards->storedFiles($card),true);
            if (!$package['ready']) throw new DomainException('Пока недостаточно данных ни для одного действия. Сохраните черновик.');
            if ($stage==='partial' && $package['full']) throw new DomainException('Весь комплект уже готов. Отправьте его одним письмом.');
            if ($stage==='final' && !$package['full']) throw new DomainException('Для второго письма нужны все поля и обязательные файлы.');
            $membershipId=(int)($snapshot['logist']??0);
            $logist=$this->responsibleLogist($central,(int)$company['id'],$membershipId);
            $settings=(new RouteExecutorReviewRecipientService($central,(int)$company['id']))->read();
            $recipients=array_map(static fn(array $row):string=>(string)$row['email'],$settings['recipients']);
            if ($settings['send_responsible_logist'] && filter_var($logist['email'],FILTER_VALIDATE_EMAIL)) $recipients[]=$logist['email'];
            $recipients=array_values(array_unique(array_map('strtolower',$recipients)));
            if (!$recipients) throw new DomainException('В «Ключи и сервисы» укажите получателей проверки исполнителей рейса.');
            $profiles=new EmployeeMailProfileService($central,(int)$company['id']);
            $sender=$profiles->defaultSender($accountId);
            $resolved=$profiles->resolve($accountId,(int)$sender['legal_entity_id']);
            $credentials=$profiles->credentials($accountId,(int)$sender['legal_entity_id']);
            if ((int)$credentials['id']!==(int)$sender['id'] || (int)$resolved['profile_id']!==(int)$sender['id']
                || (string)$credentials['email']!==(string)$sender['email']) throw new DomainException('Рабочий ящик изменился. Обновите страницу.');
            $domain=substr(strrchr((string)$sender['email'],'@')?:'',1);
            $messageId='<route-executor-'.str_replace('-','',$cardId).'-'.$stage.'@'.$domain.'>';
            $mail=RouteExecutorReviewEmail::build($package,$stage==='partial',$tripLabel);
            $subject=$tripLabel.' — исполнитель рейса — '.($stage==='partial'?'первичные данные':'полный комплект');
            $message=['from'=>(string)$sender['email'],'to'=>$recipients,'message_id'=>$messageId,
                'subject'=>$subject,'text'=>$mail['text'],'html'=>$mail['html'],
                'attachments'=>$package['attachments'] ? [RouteExecutorReviewArchive::build($package['attachments'],$tripLabel)] : []];
            DocumentMailMime::build($message);
            $configuredEvent='route_executor.'.$stage;
            $rule=null;
            foreach ((new MaxEventNotificationService($tenant))->rules() as $candidate) {
                if (($candidate['event_key']??'')===$configuredEvent) { $rule=$candidate;break; }
            }
            if (!$rule || empty($rule['is_enabled']) || empty($rule['chat_config_id'])) {
                throw new DomainException('В настройках MAX включите событие «Исполнитель рейса» и выберите чат.');
            }
            $tenant->beginTransaction();
            try {
                $locked=$tenant->prepare('SELECT revision FROM route_executor_review_cards WHERE card_id=? AND deleted_at IS NULL FOR UPDATE');
                $locked->execute([$cardId]);
                if ((int)$locked->fetchColumn()!==(int)$card['revision']) throw new DomainException('Карточка изменена другим сотрудником. Обновите страницу.');
                $row=$this->lockedRow($tenant,$cardId);
                if ($stage==='partial') {
                    if ($row && $row['stage']!=='partial_failed') throw new DomainException('Первичные данные уже переданы. Следующее письмо возможно только с полным комплектом.');
                    if ($row) {
                        $q=$tenant->prepare("UPDATE route_executor_review_sends SET stage='partial_sending',responsible_membership_id=?,partial_message_id=?,partial_mail_status='sending',partial_at=NOW(6) WHERE card_id=?");
                        $q->execute([$membershipId,$messageId,$cardId]);
                    } else {
                        $q=$tenant->prepare("INSERT INTO route_executor_review_sends(card_id,actor_account_id,responsible_membership_id,stage,partial_message_id,partial_mail_status,partial_at) VALUES(?,?,?,'partial_sending',?,'sending',NOW(6))");
                        $q->execute([$cardId,$accountId,$membershipId,$messageId]);
                    }
                } else {
                    if ($row && !in_array((string)$row['stage'],['partial','partial_failed','final_failed'],true)) throw new DomainException('Полный комплект уже передан или отправляется.');
                    if ($row) {
                        $q=$tenant->prepare("UPDATE route_executor_review_sends SET stage='final_sending',responsible_membership_id=?,final_message_id=?,final_mail_status='sending',final_at=NOW(6) WHERE card_id=?");
                        $q->execute([$membershipId,$messageId,$cardId]);
                    } else {
                        $q=$tenant->prepare("INSERT INTO route_executor_review_sends(card_id,actor_account_id,responsible_membership_id,stage,final_message_id,final_mail_status,final_at) VALUES(?,?,?,'final_sending',?,'sending',NOW(6))");
                        $q->execute([$cardId,$accountId,$membershipId,$messageId]);
                    }
                }
                $tenant->commit();$reserved=true;
            } catch (Throwable $error) { if ($tenant->inTransaction()) $tenant->rollBack();throw $error; }
            $mailStatus=(new DocumentMailTransport())->send($credentials,$message);
            $max=new MaxEventNotificationService($tenant);$deliveryId=null;
            $tenant->beginTransaction();
            try {
                $nextStage=$mailStatus==='accepted'?$stage:($mailStatus==='failed'?$stage.'_failed':$stage.'_unknown');
                $column=$stage==='partial'?'partial_mail_status':'final_mail_status';
                $q=$tenant->prepare("UPDATE route_executor_review_sends SET stage=?,{$column}=? WHERE card_id=? AND stage=?");
                $q->execute([$nextStage,$mailStatus,$cardId,$stage.'_sending']);
                if ($q->rowCount()!==1) throw new DomainException('Состояние карточки изменилось.');
                $cards->touch($cardId);
                $tenant->commit();
            } catch (Throwable $error) { if ($tenant->inTransaction()) $tenant->rollBack();throw $error; }
            $maxStatus='disabled';
            if ($mailStatus==='accepted') {
                try {
                    $tenant->beginTransaction();
                    $deliveryId=$max->enqueue('route_executor.'.$stage,$cardId.':'.$stage,[
                        'trip'=>$tripLabel,
                        'contractor'=>$package['contractor']?:'—','driver'=>$package['driver']?:'—','plate'=>$package['plate']?:'—',
                        'responsible'=>$logist['name'],'ready'=>implode(', ',$package['ready']),'author'=>$actorName,
                        'missing'=>implode("\n",$package['missing']) ?: 'нет',
                    ],$accountId);
                    $tenant->commit();
                    if ($deliveryId) $maxStatus=(string)$max->dispatch($deliveryId)['status'];
                } catch (Throwable $maxError) {
                    if ($tenant->inTransaction()) $tenant->rollBack();
                    error_log('route executor MAX notification: '.$maxError->getMessage());
                    $maxStatus='failed';
                }
            }
            $this->json(['ok'=>$mailStatus==='accepted','stage'=>$nextStage,'mail_status'=>$mailStatus,
                'max_status'=>$maxStatus,'error'=>$mailStatus==='accepted'?null:'Письмо не подтверждено. Проверьте статус отправки у администратора.']);
        } catch (Throwable $error) {
            if ($reserved && $tenant instanceof PDO && $cardId!=='' && $stage!=='') {
                try {
                    $q=$tenant->prepare("UPDATE route_executor_review_sends SET stage=?,".($stage==='partial'?'partial_mail_status':'final_mail_status')."='unknown' WHERE card_id=? AND stage=?");
                    $q->execute([$stage.'_unknown',$cardId,$stage.'_sending']);
                } catch (Throwable) {}
            }
            $this->failure($error);
        }
    }

    /** Temporary delivery-check action. It never advances the workflow. */
    private function resendCard(RouteExecutorReviewCardService $cards,PDO $central,PDO $tenant,int $companyId,int $accountId,string $actorName,string $cardId): never
    {
        $lockName='route-review-resend:'.$cardId;
        $lock=$tenant->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
        if ((int)$lock->fetchColumn()!==1) throw new DomainException('Повторная отправка этой карточки уже выполняется.');
        try {
            $card=$cards->load($cardId);
            if (!$card) throw new DomainException('Карточка не найдена.');
            $row=$this->row($tenant,$cardId);
            $stage=(string)($row['stage']??'');
            if (!in_array($stage,['partial','final'],true)) throw new DomainException('Повторить можно только подтверждённое письмо.');
            $tripId=(string)($card['snapshot']['trip_id']??'');
            if (!preg_match('/^[1-9][0-9]*$/D',$tripId)) throw new DomainException('Выберите рейс перед отправкой.');
            $tripLabel=$this->selectedTrip($tenant,(int)$tripId);
            $package=RouteExecutorReviewPackage::inspect($card['snapshot'],$cards->storedFiles($card),true);
            if (!$package['ready']) throw new DomainException('В карточке недостаточно данных для отправки.');
            $logist=$this->responsibleLogist($central,$companyId,(int)($card['snapshot']['logist']??0));
            $testChat=$tenant->prepare("SELECT c.id,c.bot_id FROM max_chats c JOIN max_bots b ON b.id=c.bot_id WHERE c.chat_id=? AND b.name=? AND c.is_active=1 AND c.max_status='active' AND b.is_active=1 LIMIT 2");
            $testChat->execute(['-70991778594913','Бэрримор']);
            $testChats=$testChat->fetchAll(PDO::FETCH_ASSOC);
            if (count($testChats)!==1) throw new DomainException('Тестовая группа MAX бота «Бэрримор» недоступна. Повторная отправка не начата.');
            $event='route_executor.'.$stage;
            $rule=null;
            foreach ((new MaxEventNotificationService($tenant))->rules() as $candidate) {
                if (($candidate['event_key']??'')===$event) { $rule=$candidate;break; }
            }
            $template=(string)($rule['message_template']??MaxEventCatalog::event($event)['template']);
            $format=MaxEventCatalog::systemFormat($event,(string)($rule['message_format']??'markdown'));
            $maxText="Повторная отправка для проверки доставки\n".MaxEventCatalog::render($event,$template,$format,[
                'trip'=>$tripLabel,'contractor'=>$package['contractor']?:'—','driver'=>$package['driver']?:'—',
                'plate'=>$package['plate']?:'—','responsible'=>$logist['name'],'ready'=>implode(', ',$package['ready']),
                'author'=>$actorName,'missing'=>implode("\n",$package['missing']) ?: 'нет',
            ]);
            if (mb_strlen($maxText)>4000) throw new DomainException('Сообщение MAX слишком длинное. Повторная отправка не начата.');
            $recipients=['info@plan-ex.ru'];
            $profiles=new EmployeeMailProfileService($central,$companyId);
            $sender=$profiles->defaultSender($accountId);
            $resolved=$profiles->resolve($accountId,(int)$sender['legal_entity_id']);
            $credentials=$profiles->credentials($accountId,(int)$sender['legal_entity_id']);
            if ((int)$credentials['id']!==(int)$sender['id'] || (int)$resolved['profile_id']!==(int)$sender['id']
                || (string)$credentials['email']!==(string)$sender['email']) throw new DomainException('Рабочий ящик изменился. Обновите страницу.');
            $domain=substr(strrchr((string)$sender['email'],'@')?:'',1);
            $messageId='<route-executor-'.str_replace('-','',$cardId).'-'.$stage.'-retry-'.bin2hex(random_bytes(8)).'@'.$domain.'>';
            $mail=RouteExecutorReviewEmail::build($package,$stage==='partial',$tripLabel);
            $message=['from'=>(string)$sender['email'],'to'=>$recipients,'message_id'=>$messageId,
                'subject'=>$tripLabel.' — исполнитель рейса — '.($stage==='partial'?'первичные данные':'полный комплект').' — повтор',
                'text'=>$mail['text'],'html'=>$mail['html'],
                'attachments'=>$package['attachments'] ? [RouteExecutorReviewArchive::build($package['attachments'],$tripLabel)] : []];
            DocumentMailMime::build($message);
            $mailStatus=(new DocumentMailTransport())->send($credentials,$message);
            $maxStatus='not_sent';
            if ($mailStatus==='accepted') {
                try {
                    (new MaxMessagingService($tenant))->sendToChat((int)$testChats[0]['id'],$maxText,$format,true,'test',$accountId,[
                        'bot_id'=>(int)$testChats[0]['bot_id'],'external_chat_id'=>'-70991778594913',
                    ]);
                    $maxStatus='sent';
                } catch (Throwable $maxError) {
                    error_log('route executor retry MAX notification: '.$maxError->getMessage());
                    $maxStatus='failed';
                }
            }
            $result=['ok'=>$mailStatus==='accepted','mail_status'=>$mailStatus,'recipients'=>$recipients,
                'max_status'=>$maxStatus,
                'error'=>$mailStatus==='accepted'?null:'Почтовый сервер не подтвердил повторную отправку. Не нажимайте повторно до проверки почты.'];
        } finally {
            $unlock=$tenant->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$lockName]);
        }
        $this->json($result);
    }

    private function saveCard(RouteExecutorReviewCardService $cards,string $cardId,int $accountId): never
    {
        $raw=(string)($_POST['snapshot']??'');
        if (strlen($raw)>262144) throw new DomainException('Карточка слишком большая.');
        $snapshot=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new DomainException('Некорректная карточка.');
        $keys=$_POST['attachment_keys']??[];
        if (!is_array($keys)) throw new DomainException('Некорректные вложения.');
        $revision=$cards->save($cardId,$accountId,(int)($_POST['revision']??0),$snapshot,$_FILES['attachments']??[],array_values($keys));
        $this->json(['ok'=>true,'revision'=>$revision]);
    }

    private function downloadCardFile(): never
    {
        try {
            [$company,,$tenant]=$this->context();
            $cardId=$this->cardId((string)$_GET['file_card']);
            $cards=new RouteExecutorReviewCardService($tenant,(int)$company['id']);
            $card=$cards->load($cardId);
            if (!$card) throw new DomainException('Карточка не найдена.');
            $file=$cards->file($card,(string)$_GET['file_id']);
            header('Content-Type: '.(string)$file['mime']);
            header('Content-Length: '.(int)$file['size']);
            header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode((string)$file['original_name']));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            readfile((string)$file['absolute_path']);
            exit;
        } catch (Throwable $error) { $this->failure($error); }
    }

    private function context(): array
    {
        $access=AccessRuntime::current();$companyId=(int)($access->companyId()?:getSessionCompanyId());
        $membershipId=(int)(AccessControlService::effectiveMembershipId()??$access->membershipId()??0);
        if ($companyId<=0 || $membershipId<=0) throw new DomainException('Войдите в компанию.');
        $central=$this->db->connection();
        $q=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");$q->execute([$companyId]);
        $company=$q->fetch(PDO::FETCH_ASSOC);if (!$company) throw new DomainException('Компания недоступна.');
        $q=$central->prepare("SELECT a.id,a.full_name FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.id=? AND m.company_id=? AND m.status='active' AND a.status='active' LIMIT 1");
        $q->execute([$membershipId,$companyId]);$actor=$q->fetch(PDO::FETCH_ASSOC);
        if (!$actor) throw new DomainException('Пользователь компании недоступен.');
        $tenant=(new Database(companyDatabaseConfig($this->config,$company)))->connection();
        return [$company,$central,$tenant,(int)$actor['id'],(string)$actor['full_name']];
    }

    private function responsibleLogist(PDO $central,int $companyId,int $membershipId): array
    {
        if ($membershipId<=0) throw new DomainException('Выберите ответственного логиста.');
        $q=$central->prepare("SELECT a.full_name,COALESCE((SELECT p.email FROM employee_mail_profiles p WHERE p.account_id=a.id AND p.company_id=m.company_id AND p.email<>'' ORDER BY p.id LIMIT 1),NULLIF(a.email,'')) email FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.id=? AND m.company_id=? AND m.status='active' AND a.status='active' LIMIT 1");
        $q->execute([$membershipId,$companyId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Ответственный логист недоступен.');
        return ['name'=>(string)$row['full_name'],'email'=>(string)$row['email']];
    }

    private function assertSchema(PDO $tenant): void
    {
        $q=$tenant->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='route_executor_review_sends'");
        if ((int)$q->fetchColumn()!==1) throw new DomainException('Передача данных ожидает обновления структуры базы данных (151).');
    }

    private function cardId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$id)) throw new DomainException('Некорректный идентификатор карточки.');
        return $id;
    }

    private function row(PDO $tenant,string $id): ?array
    { $q=$tenant->prepare('SELECT * FROM route_executor_review_sends WHERE card_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null; }
    private function lockedRow(PDO $tenant,string $id): ?array
    { $q=$tenant->prepare('SELECT * FROM route_executor_review_sends WHERE card_id=? FOR UPDATE');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null; }

    private function files(): array
    {
        $source=$_FILES['attachments']??null;$keys=$_POST['attachment_keys']??[];
        if (!$source) return [];
        if (!is_array($keys) || !is_array($source['name']??null)) throw new DomainException('Некорректные вложения.');
        $files=[];
        foreach ($source['name'] as $index=>$name) {
            if (($source['error'][$index]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            $files[]=['key'=>(string)($keys[$index]??''),'name'=>(string)$name,'tmp_name'=>(string)($source['tmp_name'][$index]??''),
                'error'=>(int)($source['error'][$index]??UPLOAD_ERR_NO_FILE),'size'=>(int)($source['size'][$index]??0)];
        }
        return $files;
    }

    private function json(array $data,int $status=200): never
    { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
    private function failure(Throwable $error): never
    { error_log('route executor review: '.$error->getMessage());$this->json(['ok'=>false,'error'=>$error instanceof DomainException?$error->getMessage():'Не удалось передать данные.'], $error instanceof DomainException?422:500); }
}
