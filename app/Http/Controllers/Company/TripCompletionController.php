<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\AccessControlService;
use App\Service\DocumentService;
use App\Service\TripCompletionService;
use DomainException;
use PDO;
use Throwable;

final class TripCompletionController
{
    public function __construct(private readonly array $config,private readonly Database $db) {}

    public function index(): void
    {
        header('Cache-Control: no-store');
        $error=null;$company=null;$trips=[];$metrics=['trips'=>0,'requests'=>0,'ready'=>0,'problems'=>0];
        $query=trim((string)($_GET['q']??''));
        $hideComplete=!isset($_GET['filter']) || isset($_GET['hide_complete']);
        $success=$_SESSION['trip_completion_success']??null;$formError=$_SESSION['trip_completion_error']??null;
        unset($_SESSION['trip_completion_success'],$_SESSION['trip_completion_error']);
        try {
            [$company,$service]=$this->context();
            if (!$service->ready()) throw new DomainException('Рабочее место ожидает обновление схемы (148).');
            $all=$service->trips('',false);
            $metrics=[
                'trips'=>count($all),
                'requests'=>array_sum(array_map(static fn(array $row):int=>(int)$row['request_count'],$all)),
                'ready'=>array_sum(array_map(static fn(array $row):int=>(int)$row['complete_count'],$all)),
                'problems'=>array_sum(array_map(static fn(array $row):int=>(int)$row['request_count']-(int)$row['complete_count'],$all)),
            ];
            $trips=$service->trips('',false);
            foreach ($trips as $index=>$trip) {
                $details=$service->trip((int)$trip['id']);
                $trips[$index]=array_merge($trip,$details);
            }
            $openTripId=max(0,(int)($_GET['trip_id']??0));
        } catch (DomainException $exception) { $error=$exception->getMessage(); }
        catch (Throwable $exception) { error_log('trip completion index failed: '.$exception->getMessage());$error='Не удалось загрузить рабочее место помощника логиста.'; }
        $pageTitle='Регистрация прибытия и убытия';$pageContext='Рейсы › Регистрация времени загрузки';
        $topbarCrumbs=[['label'=>'Рейсы','url'=>null],['label'=>'Регистрация времени загрузки','url'=>null]];
        $config=$this->config;$db=$this->db;
        ob_start();require base_path('app/View/pages/company_logistics_assistant.php');$content=(string)ob_get_clean();require base_path('app/View/layouts/main.php');
    }

    public function journal(): void
    {
        header('Cache-Control: no-store');
        $error=null;$journal=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'users'=>[]];
        $filterValue=static fn(string $key): string=>is_scalar($_GET[$key]??null)?(string)$_GET[$key]:'';
        $filters=['from'=>$filterValue('from'),'to'=>$filterValue('to'),'user'=>$filterValue('user'),
            'kind'=>$filterValue('kind'),'q'=>$filterValue('q'),'page'=>$filterValue('page')];
        try {
            [, $service]=$this->context();
            $journal=$service->journal($filters);
        } catch (Throwable $exception) {
            error_log('registration journal failed: '.$exception->getMessage());
            $error='Не удалось загрузить журнал регистрации заявок.';
        }
        $pageTitle='Журнал регистрации заявок';$pageContext='Журналы › Регистрация заявок';
        $topbarCrumbs=[['label'=>'Журналы','url'=>null],['label'=>'Регистрация заявок','url'=>null]];
        $config=$this->config;$db=$this->db;
        ob_start();require base_path('app/View/pages/company_registration_journal.php');$content=(string)ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function save(string $tripId,string $requestId): void
    {
        verifyCsrfRequest();
        try {
            [, $service,$actor,$login]=$this->context();$this->assertReady($service);
            $service->save((int)$tripId,(int)$requestId,$_POST,$actor,$login);
            if ($this->wantsJson()) $this->json($this->payload($service,(int)$tripId,(int)$requestId));
            $this->success('Данные заявки сохранены.');
        }
        catch (Throwable $exception) {
            if ($this->wantsJson()) $this->jsonError($exception,'Не удалось сохранить заявку.');
            $this->failure($exception,'Не удалось сохранить заявку.');
        }
        $this->back((int)$tripId);
    }

    public function upload(string $tripId,string $requestId): void
    {
        verifyCsrfRequest();
        try {
            [, $service,$actor,$login]=$this->context();$this->assertReady($service);
            $files=$this->uploadedFiles('documents');
            if ($files===[]) throw new DomainException('Выберите хотя бы один файл.');
            if ($service->activeDocumentCount((int)$tripId,(int)$requestId)+count($files)>2) throw new DomainException('К заявке можно добавить не более двух сканов ТН.');
            $total=array_sum(array_map(static fn(array $file):int=>(int)($file['size']??0),$files));
            if ($total>80*1024*1024) throw new DomainException('Общий размер файлов больше 80 МБ.');
            foreach ($files as $file) $service->upload((int)$tripId,(int)$requestId,$file,$actor,true,$login);
            if ($this->wantsJson()) $this->json($this->payload($service,(int)$tripId,(int)$requestId));
            $this->success('Сканы ТН загружены: '.count($files).'.');
        } catch (Throwable $exception) {
            if ($this->wantsJson()) $this->jsonError($exception,'Не удалось загрузить сканы ТН.');
            $this->failure($exception,'Не удалось загрузить сканы ТН.');
        }
        $this->back((int)$tripId);
    }

    public function deleteFile(string $tripId,string $requestId,string $documentId): void
    {
        verifyCsrfRequest();
        try {
            [, $service,$actor,$login]=$this->context();$this->assertReady($service);
            $service->softDelete((int)$tripId,(int)$requestId,(int)$documentId,$actor,$login);
            if ($this->wantsJson()) $this->json($this->payload($service,(int)$tripId,(int)$requestId));
            $this->success('Файл удалён из комплекта.');
        }
        catch (Throwable $exception) {
            if ($this->wantsJson()) $this->jsonError($exception,'Не удалось удалить файл.');
            $this->failure($exception,'Не удалось удалить файл.');
        }
        $this->back((int)$tripId);
    }

    public function download(string $tripId,string $documentId): void
    {
        try {
            [, $service]=$this->context();$this->assertReady($service);$document=$service->document((int)$tripId,(int)$documentId);$path=$service->absoluteDocumentPath($document);
            header('Content-Type: '.(string)$document['mime_type']);header('Content-Length: '.filesize($path));
            $disposition=isset($_GET['preview'])?'inline':'attachment';
            header("Content-Disposition: {$disposition}; filename*=UTF-8''".rawurlencode(DocumentService::safeDownloadName((string)$document['download_name'])));
            readfile($path);
        } catch (Throwable) { http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Файл не найден.'; }
    }

    public function report(string $tripId): void
    {
        $path=null;
        try {
            [, $service]=$this->context();$this->assertReady($service);$trip=$service->trip((int)$tripId);$path=$service->xlsx($trip);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Length: '.filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode('Рейс_'.$trip['business_id'].'_сводный_отчет.xlsx'));readfile($path);
        } catch (Throwable) { http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Отчёт недоступен.'; }
        finally { if (is_string($path) && is_file($path)) @unlink($path); }
    }

    public function package(string $tripId): void
    {
        $path=null;
        try {
            [, $service]=$this->context();$this->assertReady($service);$trip=$service->trip((int)$tripId);$path=$service->package($trip);
            header('Content-Type: application/zip');header('Content-Length: '.filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode('Рейс_'.$trip['business_id'].'_ТН_и_отчет.zip'));readfile($path);
        } catch (Throwable) { http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Архив недоступен.'; }
        finally { if (is_string($path) && is_file($path)) @unlink($path); }
    }

    /** @return array{0:array<string,mixed>,1:TripCompletionService,2:int,3:string} */
    private function context(): array
    {
        $access=AccessRuntime::current();$companyId=(int)($access->companyId()?:getSessionCompanyId());
        $actor=(int)(AccessControlService::effectiveMembershipId()??$access->membershipId()??0);
        if ($companyId<=0 || $actor<=0) throw new DomainException('Войдите в компанию.');
        $central=$this->db->connection();$stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");$stmt->execute([$companyId]);
        $company=$stmt->fetch(PDO::FETCH_ASSOC);if (!$company) throw new DomainException('Компания недоступна.');
        $identity=$central->prepare("SELECT a.login FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.id=? AND m.company_id=? AND m.status='active' AND a.status='active' LIMIT 1");
        $identity->execute([$actor,$companyId]);$login=$identity->fetchColumn();
        if (!is_string($login) || $login==='') throw new DomainException('Пользователь компании недоступен.');
        $tenant=(new Database(companyDatabaseConfig($this->config,$company)))->connection();
        return [$company,new TripCompletionService($tenant,$companyId),$actor,$login];
    }

    private function assertReady(TripCompletionService $service): void
    { if (!$service->ready()) throw new DomainException('Рабочее место ожидает обновление схемы (148).'); }

    /** @return list<array<string,mixed>> */
    private function uploadedFiles(string $field): array
    {
        $source=$_FILES[$field]??null;if (!is_array($source)) return [];
        if (!is_array($source['name']??null)) return (($source['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)?[]:[$source];
        $files=[];
        foreach ($source['name'] as $index=>$name) {
            if (($source['error'][$index]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            $files[]=['name'=>$name,'type'=>$source['type'][$index]??'','tmp_name'=>$source['tmp_name'][$index]??'','error'=>$source['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$source['size'][$index]??0];
        }
        return $files;
    }

    private function success(string $message): void { $_SESSION['trip_completion_success']=$message; }
    private function failure(Throwable $exception,string $fallback): void
    { $_SESSION['trip_completion_error']=$exception instanceof DomainException?$exception->getMessage():$fallback;error_log('trip completion action failed: '.$exception->getMessage()); }
    private function back(int $tripId): never { header('Location: '.app_url('/company/logistics-assistant?trip_id='.$tripId));exit; }

    private function wantsJson(): bool
    {
        return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json')
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';
    }

    /** @return array<string,mixed> */
    private function payload(TripCompletionService $service,int $tripId,int $requestId): array
    {
        $trip=$service->trip($tripId);$request=null;
        foreach ($trip['requests'] as $candidate) if ((int)$candidate['id']===$requestId) {$request=$candidate;break;}
        if (!is_array($request)) throw new DomainException('Заявка рейса не найдена.');
        $request['documents']=array_map(static fn(array $document):array=>[
            'id'=>(int)$document['id'],
            'download_name'=>(string)$document['download_name'],
            'download_url'=>app_url('/company/logistics-assistant/'.$tripId.'/documents/'.(int)$document['id']),
            'delete_url'=>app_url('/company/logistics-assistant/'.$tripId.'/requests/'.$requestId.'/documents/'.(int)$document['id'].'/delete'),
        ],$request['documents']);
        $missing=['scan'=>0,'time'=>0,'driver'=>0,'sender'=>0];
        foreach ($trip['requests'] as $item) {
            if (empty($item['documents'])) $missing['scan']++;
            if (empty($item['arrived_at']) || empty($item['departed_at'])) $missing['time']++;
            if ((int)$item['driver_signature_present']!==1) $missing['driver']++;
            if ((int)$item['sender_signature_present']!==1) $missing['sender']++;
        }
        $summary=[];
        if ($missing['scan']>0) $summary[]='ТН '.$missing['scan'];
        if ($missing['time']>0) $summary[]='время '.$missing['time'];
        if ($missing['driver']>0) $summary[]='подпись водителя '.$missing['driver'];
        if ($missing['sender']>0) $summary[]='подпись отправителя '.$missing['sender'];
        return ['ok'=>true,'request'=>$request,'trip'=>[
            'complete_count'=>(int)$trip['complete_count'],
            'request_count'=>(int)$trip['request_count'],
            'document_count'=>array_sum(array_map(static fn(array $item):int=>count($item['documents']),$trip['requests'])),
            'missing_summary'=>$summary,
        ]];
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload,int $status=200): never
    {
        http_response_code($status);header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }

    private function jsonError(Throwable $exception,string $fallback): never
    {
        error_log('trip completion ajax action failed: '.$exception->getMessage());
        $message=$exception instanceof DomainException?$exception->getMessage():$fallback;
        $this->json(['ok'=>false,'message'=>$message],$exception instanceof DomainException?422:500);
    }
}
