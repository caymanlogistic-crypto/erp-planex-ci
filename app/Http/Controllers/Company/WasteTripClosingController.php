<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\AccessControlService;
use App\Service\DocumentMailTransport;
use App\Service\EmployeeMailProfileService;
use App\Service\EntityResponsibilityService;
use App\Service\FinanceInvoiceService;
use App\Service\WasteTripClosingPackageStore;
use App\Service\WasteTripClosingArchiveService;
use App\Service\WasteTripClosingService;
use App\Service\WasteTripClosingReportService;
use App\Service\WasteTripClosingTransportService;
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
        $registryView=current_app_path()==='/company/logistics/closing-documents';
        $trips=[];$reportRows=[];$error=null;$schemaReady=false;$canCreateInvoice=false;$canFilterManagers=false;$canFilterLeader=false;$completionQuarters=[];$contractors=[];$clients=[];$responsibleUsers=[];$viewStateKey='';
        $success=$_SESSION['waste_trip_closing_success']??null;
        $flashError=$_SESSION['waste_trip_closing_error']??null;
        unset($_SESSION['waste_trip_closing_success'],$_SESSION['waste_trip_closing_error']);
        try {
            [$company, $pdo,,,$accountId]=$this->context();
            $viewStateKey='planex:trip-closing:v1:'.(int)$company['id'].':'.$accountId;
            $canFilterLeader=AccessRuntime::current()->isSystemOwner();
            $canFilterManagers=AccessRuntime::current()->isActive()
                ? AccessControlService::effectiveDataScope('waste_trip')==='all'
                : in_array((string)($_SESSION['role_code']??''),['superadmin','company_owner','senior_logist'],true);
            if (!$registryView || $canFilterManagers) {
                $responsibleUsers=EntityResponsibilityService::eligibleRecipients($this->db->connection(),$pdo,(int)$company['id']);
            }
            $schemaReady=WasteTripClosingService::ready($pdo);
            if ($schemaReady) {
                $selectedTripId=filter_var($_GET['trip_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
                $trips=WasteTripClosingService::trips($pdo,$selectedTripId);
                $transport=WasteTripClosingTransportService::forTrips($pdo,array_map(static fn(array $trip):int=>(int)$trip['id'],$trips));
                foreach ($trips as &$trip) $trip['transport']=$transport[(int)$trip['id']]??null;
                unset($trip);
                $reportRows=WasteTripClosingReportService::rows($trips);
                if ($canFilterLeader) {
                    foreach ($reportRows as $row) {
                        $date=(string)($row['unloading_date']??'');
                        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])-\d{2}$/D',$date,$parts)) continue;
                        $quarter=(int)ceil((int)$parts[2]/3);
                        $completionQuarters[$parts[1].'-Q'.$quarter]=[$quarter,substr($parts[1],-2)];
                    }
                    krsort($completionQuarters,SORT_STRING);
                }
            }
            $canCreateInvoice=AccessControlService::canAccessPage('company.finance.invoices');
            if ($canCreateInvoice) {
                $contractors=FinanceInvoiceService::fetchContractorsForSelect($pdo);
                $clients=FinanceInvoiceService::fetchClientsForSelect($pdo);
            }
        } catch (Throwable $exception) {
            error_log('waste trip closing index: '.$exception->getMessage());
            $error='Не удалось загрузить закрывающие документы рейсов.';
        }
        $pageTitle=$registryView?'Закрывающие документы':'Сбор закрывающих документов';
        $pageContext=$registryView?'Логисты › Закрывающие документы':'Операции › Сбор закрывающих документов';
        $topbarCrumbs=$registryView
            ?[['label'=>'Логисты','url'=>null],['label'=>'Закрывающие документы','url'=>null]]
            :[['label'=>'Операции','url'=>null],['label'=>'Сбор закрывающих документов','url'=>null]];
        $config=$this->config;$db=$this->db;
        ob_start();require base_path($registryView?'app/View/pages/company_waste_trip_closing_registry.php':'app/View/pages/company_waste_trip_closing.php');$content=(string)ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function summaryXlsx(): void
    {
        header('Cache-Control: no-store');
        $path=null;
        try{
            [,$pdo]=$this->context();
            if(!WasteTripClosingService::ready($pdo))throw new DomainException('Раздел ожидает обновление схемы.');
            $trips=$this->selectedReportTrips($pdo);
            $transport=WasteTripClosingTransportService::forTrips($pdo,array_map(static fn(array $trip):int=>(int)$trip['id'],$trips));
            foreach($trips as &$trip)$trip['transport']=$transport[(int)$trip['id']]??null;
            unset($trip);
            $registryView=current_app_path()==='/company/logistics/closing-documents/summary.xlsx';
            $path=WasteTripClosingReportService::xlsx(WasteTripClosingReportService::rows($trips),$registryView);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="trip-closing-summary.xlsx"');
            header('Content-Length: '.filesize($path));
            readfile($path);
        }catch(Throwable $exception){
            error_log('waste trip closing summary: '.$exception->getMessage());
            http_response_code(400);header('Content-Type: text/plain; charset=UTF-8');
            echo $exception instanceof DomainException?$exception->getMessage():'Не удалось сформировать Excel.';
        }finally{if($path!==null)@unlink($path);}
    }

    public function archiveZip(): void
    {
        header('Cache-Control: no-store');
        $path=null;
        $stage='context';
        try {
            [$company,$pdo]=$this->context();
            $stage='selection';
            if (!WasteTripClosingService::ready($pdo)) throw new DomainException('Раздел ожидает обновление схемы.');
            $trips=$this->selectedReportTrips($pdo);
            if ($trips===[]) throw new DomainException('Выберите рейсы для архива.');
            $stage='transport';
            $transport=WasteTripClosingTransportService::forTrips($pdo,array_map(static fn(array $trip):int=>(int)$trip['id'],$trips));
            foreach ($trips as &$trip) $trip['transport']=$transport[(int)$trip['id']]??null;
            unset($trip);
            $stage='zip';
            $path=WasteTripClosingArchiveService::create((int)$company['id'],$trips);
            $stage='response';
            header('Content-Type: application/zip');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode('Рейсы_закрывающие_документы.zip'));
            header('Content-Length: '.filesize($path));
            readfile($path);
        } catch (Throwable $exception) {
            error_log('waste trip closing archive ['.$stage.'] '.get_class($exception).': '.$exception->getMessage());
            http_response_code($exception instanceof DomainException?400:500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $exception instanceof DomainException || $exception instanceof \RuntimeException
                ? $exception->getMessage() : 'Ошибка формирования архива: '.$stage.' ('.get_class($exception).').';
        } finally { if ($path!==null) @unlink($path); }
    }

    /** @return list<array<string,mixed>> */
    private function selectedReportTrips(PDO $pdo): array
    {
        $raw=(string)($_GET['ids']??'');
        if ($raw!==''&&!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D',$raw)) throw new DomainException('Некорректный список рейсов.');
        $ids=$raw===''?[]:array_values(array_unique(array_map('intval',explode(',',$raw))));
        if (count($ids)>500) throw new DomainException('Слишком много рейсов в отчёте.');
        $trips=WasteTripClosingService::trips($pdo);
        $trips=array_values(array_filter($trips,static fn(array $trip):bool=>in_array((int)$trip['id'],$ids,true)));
        $position=array_flip($ids);
        usort($trips,static fn(array $left,array $right):int=>($position[(int)$left['id']]??PHP_INT_MAX)<=>($position[(int)$right['id']]??PHP_INT_MAX));
        return $trips;
    }

    public function upload(string $tripId): void
    {
        verifyCsrfRequest();
        try {
            [$company,$pdo,$userId,$role]=$this->context();
            if (!WasteTripClosingService::ready($pdo)) throw new DomainException('Раздел ожидает обновление схемы.');
            WasteTripClosingService::addFiles($pdo,(int)$company['id'],(int)$tripId,
                trim((string)($_POST['slot_code']??'')),trim((string)($_POST['requirement_code']??'')),
                trim((string)($_POST['custom_title']??'')),$this->uploadedFiles('document_files'),$userId,$role);
            $_SESSION['waste_trip_closing_success']='Документ добавлен.';
        } catch (Throwable $exception) { $this->fail($exception,'Не удалось добавить документ.'); }
        $this->back((int)$tripId);
    }

    public function uploadDiagnosticCard(string $tripId): void
    {
        verifyCsrfRequest();
        try {
            [$company,$pdo,$userId,$role]=$this->context();
            WasteTripClosingTransportService::addDiagnosticCard($pdo,(int)$company['id'],(int)$tripId,
                (int)($_POST['vehicle_unit_id']??0),$this->uploadedFiles('card_files'),$userId,$role);
            $_SESSION['waste_trip_closing_success']='Диагностическая карта добавлена.';
        } catch (Throwable $exception) { $this->fail($exception,'Не удалось добавить диагностическую карту.'); }
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
            $missing=[];$present=[];
            foreach ($trip['documents'] as $document) {
                $key=(string)$document['slot_code'];
                if ($key==='extra') $key.=':'.(string)$document['custom_title'];
                $present[$key]=true;
            }
            foreach (WasteTripClosingService::SLOTS as $slot=>$label) if (!isset($present[$slot])) $missing[]=$label;
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
    /** @return list<array<string,mixed>> */
    private function uploadedFiles(string $key): array
    {
        $input=$_FILES[$key]??null;
        if (!is_array($input) || !is_array($input['name']??null)) return [];
        $files=[];
        foreach ($input['name'] as $index=>$name) {
            if (($input['error'][$index]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            $files[]=[
                'name'=>(string)$name,'type'=>(string)($input['type'][$index]??''),
                'tmp_name'=>(string)($input['tmp_name'][$index]??''),
                'size'=>(int)($input['size'][$index]??0),'error'=>(int)($input['error'][$index]??UPLOAD_ERR_NO_FILE),
            ];
        }
        return $files;
    }
    private function back(int $tripId, ?string $stage=null): never {
        $base=str_starts_with(current_app_path(),'/company/logistics/closing-documents')
            ?'/company/logistics/closing-documents':'/company/trips/closing-documents';
        header('Location: '.app_url($base.'?trip_id='.$tripId.($stage?'&stage='.$stage:'')));exit;
    }
}
