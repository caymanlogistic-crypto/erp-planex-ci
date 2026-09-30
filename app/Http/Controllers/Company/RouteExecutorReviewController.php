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
use App\Service\RouteExecutorReviewEmail;
use App\Service\RouteExecutorReviewPackage;
use App\Service\RouteExecutorReviewRecipientService;
use DomainException;
use PDO;
use Throwable;

final class RouteExecutorReviewController
{
    public function __construct(private readonly array $config,private readonly Database $db) {}

    public function config(): void
    {
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
            [,,$tenant,$accountId]=$this->context();$this->assertSchema($tenant);
            $cardId=$this->cardId((string)($_GET['card_id']??''));
            $row=$this->row($tenant,$cardId);
            if ($row && (int)$row['actor_account_id']!==$accountId) throw new DomainException('Карточка принадлежит другому сотруднику.');
            $this->json(['ok'=>true,'stage'=>$row['stage']??'new','mail_status'=>$row ? ($row['final_mail_status']??$row['partial_mail_status']) : null]);
        } catch (Throwable $error) { $this->failure($error); }
    }

    public function send(): void
    {
        verifyCsrfRequest();header('Cache-Control: no-store');
        $reserved=false;$messageId=null;$tenant=null;$cardId='';$stage='';
        try {
            [$company,$central,$tenant,$accountId,$actorName]=$this->context();$this->assertSchema($tenant);
            if (isPostTruncated()) throw new DomainException('Размер данных превышает серверный лимит.');
            $sizeError=validateTotalUploadSize();if ($sizeError!=='') throw new DomainException($sizeError);
            $cardId=$this->cardId((string)($_POST['card_id']??''));
            $stage=(string)($_POST['stage']??'');
            if (!in_array($stage,['partial','final'],true)) throw new DomainException('Неизвестный этап отправки.');
            $raw=(string)($_POST['snapshot']??'');
            if (strlen($raw)>262144) throw new DomainException('Карточка слишком большая.');
            $snapshot=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) throw new DomainException('Некорректная карточка.');
            $files=$this->files();
            $package=RouteExecutorReviewPackage::inspect($snapshot,$files);
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
            $mail=RouteExecutorReviewEmail::build($package,$stage==='partial');
            $subject='Исполнитель рейса — '.($stage==='partial'?'первичные данные':'полный комплект');
            $message=['from'=>(string)$sender['email'],'to'=>$recipients,'message_id'=>$messageId,
                'subject'=>$subject,'text'=>$mail['text'],'html'=>$mail['html'],'attachments'=>$package['attachments']];
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
                $row=$this->lockedRow($tenant,$cardId);
                if ($row && (int)$row['actor_account_id']!==$accountId) throw new DomainException('Карточка принадлежит другому сотруднику.');
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
                $tenant->commit();
            } catch (Throwable $error) { if ($tenant->inTransaction()) $tenant->rollBack();throw $error; }
            $maxStatus='disabled';
            if ($mailStatus==='accepted') {
                try {
                    $tenant->beginTransaction();
                    $deliveryId=$max->enqueue('route_executor.'.$stage,$cardId.':'.$stage,[
                        'contractor'=>$package['contractor']?:'—','driver'=>$package['driver']?:'—','plate'=>$package['plate']?:'—',
                        'responsible'=>$logist['name'],'ready'=>implode(', ',$package['ready']),'author'=>$actorName,
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
