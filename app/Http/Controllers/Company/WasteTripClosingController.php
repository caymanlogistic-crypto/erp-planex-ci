<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\AccessControlService;
use App\Service\DocumentMailTransport;
use App\Service\EmployeeMailProfileService;
use App\Service\FinanceInvoiceService;
use App\Service\WasteTripClosingPackageStore;
use App\Service\WasteTripClosingService;
use App\Service\WasteTripClosingInvoiceService;
use DomainException;
use PDO;
use Throwable;

final class WasteTripClosingController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        header('Cache-Control: no-store');
        $trips=[];$error=null;$schemaReady=false;$canCreateInvoice=false;$contractors=[];$clients=[];
        $success=$_SESSION['waste_trip_closing_success']??null;
        $flashError=$_SESSION['waste_trip_closing_error']??null;
        unset($_SESSION['waste_trip_closing_success'],$_SESSION['waste_trip_closing_error']);
        try {
            [, $pdo]=$this->context();
            $schemaReady=WasteTripClosingService::ready($pdo);
            if ($schemaReady) $trips=WasteTripClosingService::trips($pdo);
            $canCreateInvoice=AccessControlService::canAccessPage('company.finance.invoices');
            if ($canCreateInvoice) {
                $contractors=FinanceInvoiceService::fetchContractorsForSelect($pdo);
                $clients=FinanceInvoiceService::fetchClientsForSelect($pdo);
            }
        } catch (Throwable $exception) {
            error_log('waste trip closing index: '.$exception->getMessage());
            $error='Не удалось загрузить закрывающие документы рейсов.';
        }
        $pageTitle='Сбор закрывающих документов';
        $pageContext='Рейсы › Сбор закрывающих документов';
        $topbarCrumbs=[['label'=>'Рейсы','url'=>null],['label'=>'Сбор закрывающих документов','url'=>null]];
        $config=$this->config;$db=$this->db;
        ob_start();require base_path('app/View/pages/company_waste_trip_closing.php');$content=(string)ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function upload(string $tripId): void
    {
        verifyCsrfRequest();
        try {
            [$company,$pdo,$userId,$role]=$this->context();
            if (!WasteTripClosingService::ready($pdo)) throw new DomainException('Раздел ожидает обновление схемы.');
            WasteTripClosingService::addFile($pdo,(int)$company['id'],(int)$tripId,
                trim((string)($_POST['slot_code']??'')),trim((string)($_POST['requirement_code']??'')),
                trim((string)($_POST['custom_title']??'')),(array)($_FILES['document_file']??[]),$userId,$role);
            $_SESSION['waste_trip_closing_success']='Документ добавлен.';
        } catch (Throwable $exception) { $this->fail($exception,'Не удалось добавить документ.'); }
        $this->back((int)$tripId);
    }

    public function invoiceCandidates(string $tripId): void
    {
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try {
            if(!AccessControlService::canAccessPage('company.finance.invoices')) throw new DomainException('Нет доступа к счетам.');
            [, $pdo]=$this->context();
            $items=WasteTripClosingInvoiceService::candidates($pdo,(int)$tripId);
            echo json_encode(['items'=>$items],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        } catch(Throwable $error) {
            http_response_code($error instanceof DomainException?400:500);
            echo json_encode(['error'=>$error instanceof DomainException?$error->getMessage():'Не удалось загрузить счета.'],JSON_UNESCAPED_UNICODE);
        }
    }

    public function linkInvoice(string $tripId): void
    {
        verifyCsrfRequest();
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try {
            if(!AccessControlService::canAccessPage('company.finance.invoices')) throw new DomainException('Нет доступа к счетам.');
            [, $pdo,$userId,$role]=$this->context();
            WasteTripClosingInvoiceService::linkExisting($pdo,(int)$tripId,(int)($_POST['invoice_id']??0),['user_id'=>$userId,'role_code'=>$role]);
            echo json_encode(['ok'=>true]);
        } catch(Throwable $error) {
            http_response_code($error instanceof DomainException?400:500);
            error_log('waste trip closing invoice link: '.$error->getMessage());
            echo json_encode(['error'=>$error instanceof DomainException?$error->getMessage():'Не удалось связать счёт с рейсом.'],JSON_UNESCAPED_UNICODE);
        }
    }

    public function remove(string $tripId,string $documentId): void
    {
        verifyCsrfRequest();
        try {
            [, $pdo]=$this->context();
            WasteTripClosingService::removeFile($pdo,(int)$tripId,(int)$documentId);
            $_SESSION['waste_trip_closing_success']='Документ убран из комплекта.';
        } catch (Throwable $exception) { $this->fail($exception,'Не удалось убрать документ.'); }
        $this->back((int)$tripId);
    }

    public function download(string $tripId,string $documentId): void
    {
        try {
            [$company,$pdo]=$this->context();
            WasteTripClosingService::trip($pdo,(int)$tripId);
            $stmt=$pdo->prepare("SELECT d.original_name,d.relative_path,d.mime_type,d.file_size FROM waste_trip_closing_documents c
                JOIN documents d ON d.id=c.document_id AND d.deleted_at IS NULL
                WHERE c.id=? AND c.waste_trip_id=? AND c.deleted_at IS NULL LIMIT 1");
            $stmt->execute([(int)$documentId,(int)$tripId]);$doc=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new DomainException('Документ недоступен.');
            $prefix='companies/'.(int)$company['id'].'/documents/waste_trip/'.(int)$tripId.'/';
            if (!str_starts_with((string)$doc['relative_path'],$prefix)) throw new DomainException('Документ недоступен.');
            $path=storage_path((string)$doc['relative_path']);
            $root=realpath(storage_path('companies/'.(int)$company['id'].'/documents/waste_trip/'.(int)$tripId));
            $resolved=realpath($path);
            if ($root===false || $resolved===false || !str_starts_with($resolved,$root.DIRECTORY_SEPARATOR) || !is_file($resolved)) throw new DomainException('Документ недоступен.');
            header('Content-Type: '.(string)$doc['mime_type']);
            header('X-Content-Type-Options: nosniff');
            $disposition=in_array(strtolower((string)$doc['mime_type']),['application/pdf','image/jpeg','image/png','image/webp','image/tiff'],true)?'inline':'attachment';
            header('Content-Disposition: '.$disposition.'; filename*=UTF-8\'\''.rawurlencode((string)$doc['original_name']));
            header('Content-Length: '.filesize($resolved));readfile($resolved);
        } catch (Throwable) {http_response_code(404);echo 'Документ недоступен.';}
    }

    public function submit(string $tripId): void
    {
        verifyCsrfRequest();
        if (!WasteTripClosingService::REVIEW_EMAIL_ENABLED) {
            http_response_code(403);
            echo 'Отправка закрывающих документов на почту временно отключена.';
            return;
        }
        $token=null;$store=null;$messageId=null;$mailStarted=false;
        try {
            [$company,$pdo,$userId,,$accountId]=$this->context();
            if (!WasteTripClosingService::ready($pdo)) throw new DomainException('Раздел ожидает обновление схемы.');
            $trip=WasteTripClosingService::trip($pdo,(int)$tripId);
            $recipient=(string)$trip['recipient_email'];
            $profile=new EmployeeMailProfileService($this->db->connection(),(int)$company['id']);
            $sender=$profile->defaultSender($accountId);
            $credentials=$profile->credentials($accountId,(int)$sender['legal_entity_id']);
            if ((string)$credentials['email']!==(string)$sender['email']) throw new DomainException('Рабочий ящик изменился. Обновите страницу.');
            $origin=rtrim((string)env('APP_PUBLIC_ORIGIN','https://plan-ex.ru'),'/');
            if (!preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]{2,5})?$~D',$origin)
                && !preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~D',$origin)) throw new DomainException('Публичный адрес ERP настроен неверно.');
            $domain=substr(strrchr((string)$sender['email'],'@')?:'',1);
            $messageId='<trip-closing-'.$tripId.'-'.bin2hex(random_bytes(8)).'@'.$domain.'>';
            WasteTripClosingService::beginReview($pdo,(int)$tripId,(int)($_POST['version']??-1),$recipient,$messageId);
            $store=new WasteTripClosingPackageStore((int)$company['id']);
            $token=$store->publish((int)$tripId,(string)$trip['business_id'],$trip['documents']);
            $url=$origin.app_url('/trip-closing-package/'.(int)$company['id'].'/'.$token);
            $missing=[];$present=array_column($trip['documents'],'slot_code');
            foreach (WasteTripClosingService::SLOTS as $slot=>$label) if (!in_array($slot,$present,true)) $missing[]=$label;
            $title='Рейс #'.$trip['business_id'].' — закрывающие документы на проверку';
            $summary='Получено '.count($present).' документов. '.($missing?'Не хватает: '.implode(', ',$missing).'.':'Комплект собран.');
            $plain=$title."\n".$summary."\nАрхив документов (хранится 30 дней): ".$url;
            $safeTitle=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $safeSummary=htmlspecialchars($summary,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $safeUrl=htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $html='<div style="font:13px Arial,sans-serif;color:#202020"><h2>'.$safeTitle.'</h2><p>'.$safeSummary.'</p><p><a href="'.$safeUrl.'" style="background:#87511f;color:#fff;padding:10px 14px;text-decoration:none">Скачать архив</a> · Хранится 30 дней</p></div>';
            $mailStarted=true;
            $state=(new DocumentMailTransport())->send($credentials,['from'=>(string)$sender['email'],'to'=>[$recipient],
                'message_id'=>$messageId,'subject'=>$title,'text'=>$plain,'html'=>$html,'attachments'=>[]]);
            WasteTripClosingService::finishReview($pdo,(int)$tripId,$messageId,$state,$token);
            $_SESSION['waste_trip_closing_success']=$state==='accepted'?'Передано на проверку. Письмо отправлено.':
                ($state==='unknown'?'Результат отправки неизвестен. Проверьте почтовый ящик перед повторной отправкой.':'Письмо не отправлено. Проверьте рабочий ящик.');
        } catch (Throwable $exception) {
            if ($messageId!==null && isset($pdo)) {
                try {WasteTripClosingService::finishReview($pdo,(int)$tripId,$messageId,$mailStarted?'unknown':'failed',$token);} catch (Throwable) {}
            }
            if (!$mailStarted && $token!==null && $store!==null) $store->discard($token);
            $this->fail($exception,'Не удалось передать комплект на проверку.');
        }
        $this->back((int)$tripId);
    }

    public function confirmNotSent(string $tripId): void
    {
        verifyCsrfRequest();
        try {
            if (($_POST['confirm_not_sent']??'')!=='1') throw new DomainException('Подтвердите проверку исходящих писем.');
            [, $pdo]=$this->context();
            WasteTripClosingService::confirmNotSent($pdo,(int)$tripId,(int)($_POST['version']??-1));
            $_SESSION['waste_trip_closing_success']='Письмо подтверждено как неотправленное. Можно повторить отправку.';
        } catch (Throwable $exception) {$this->fail($exception,'Не удалось обновить статус отправки.');}
        $this->back((int)$tripId);
    }

    public function sharedPackage(string $companyId,string $token): void
    {
        try {
            $archive=(new WasteTripClosingPackageStore((int)$companyId))->download($token);
            header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
            header('Content-Type: application/zip');header('Content-Length: '.$archive['size']);
            header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode($archive['name']));
            readfile($archive['path']);
        } catch (Throwable) {http_response_code(404);echo 'Архив недоступен.';}
    }

    /** @return array{0:array<string,mixed>,1:PDO,2:int,3:string,4:int} */
    private function context(): array
    {
        $access=AccessRuntime::current();$companyId=(int)($access->companyId()?:getSessionCompanyId());
        $actor=(int)(AccessControlService::effectiveMembershipId()??$access->membershipId()??0);
        if ($companyId<=0 || $actor<=0) throw new DomainException('Войдите в компанию.');
        $central=$this->db->connection();
        $companyQuery=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
        $companyQuery->execute([$companyId]);$company=$companyQuery->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new DomainException('Компания недоступна.');
        $identity=$central->prepare("SELECT a.id FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.id=? AND m.company_id=? AND m.status='active' AND a.status='active' LIMIT 1");
        $identity->execute([$actor,$companyId]);$accountId=(int)$identity->fetchColumn();
        if ($accountId<=0) throw new DomainException('Пользователь компании недоступен.');
        $pdo=(new Database(companyDatabaseConfig($this->config,$company)))->connection();
        return [$company,$pdo,(int)($_SESSION['user_id']??0),(string)($_SESSION['role_code']??'company_owner'),$accountId];
    }

    private function fail(Throwable $exception,string $fallback): void
    {
        error_log('waste trip closing action: '.$exception->getMessage());
        $_SESSION['waste_trip_closing_error']=$exception instanceof DomainException?$exception->getMessage():$fallback;
    }
    private function back(int $tripId): never {header('Location: '.app_url('/company/trips/closing-documents?trip_id='.$tripId));exit;}
}
