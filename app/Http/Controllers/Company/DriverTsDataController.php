<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\DocumentService;
use App\Service\DriverTsDataService;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DriverTsDataController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    private function context(): ?array
    {
        $companyId = (int)(\getSessionCompanyId() ?? 0);
        if ($companyId <= 0) return null;
        $stmt = $this->db->connection()->prepare('SELECT * FROM companies WHERE id=?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || ($company['status'] ?? '') !== 'active') return null;
        $pdo = (new Database(\companyDatabaseConfig($this->config, $company)))->connection();
        return [$companyId, $company, $pdo, new DriverTsDataService($pdo)];
    }

    public function index(): void
    {
        $config=$this->config; $db=$this->db;
        $pageTitle='Данные Вод и ТС';
        $pageContext='Данные Вод и ТС › Компания';
        $pageStylesheets[]='/assets/css/route-executor-list-v016.css';
        $pageStylesheets[]='/assets/css/registry-components.css';
        $pageScripts[]='/assets/js/driver-ts-data.js';
        $pageScripts[]='/assets/js/table-reference-select.js';
        $pageScripts[]='/assets/js/registry-components.js';
        $company=null; $rows=[]; $error=null;
        try {
            $context=$this->context();
            if ($context !== null) {
                [, $company, $pdo, $service]=$context;
                $pageContext='Данные Вод и ТС › Компания: '.$company['name'];
                $rows=$service->listing();
                $logists=\App\Service\EntityResponsibilityService::eligibleRecipients($this->db->connection(),$pdo,(int)$company['id']);
                $names=[];
                foreach ($logists as $logist) $names[(int)$logist['membership_id']]=(string)$logist['full_name'];
                foreach ($rows as &$row) $row['responsible_name']=$names[(int)($row['responsible_membership_id']??0)]??($row['created_by_name']??'—');
                unset($row);
            }
        } catch (Throwable $e) { error_log('Driver TS list failed: '.$e->getMessage()); $error='Не удалось загрузить данные водителей и ТС.'; }
        ob_start(); require \base_path('app/View/pages/company_driver_ts_data.php'); $content=ob_get_clean();
        require \base_path('app/View/layouts/main.php');
    }

    public function detail(string $id): void
    {
        try {
            $context=$this->context();
            $detail=$context === null ? null : $context[3]->detail((int)$id);
            if ($detail === null) { http_response_code(404); return; }
            $sections=DriverTsDataService::tableRows($detail);
            header('Content-Type: text/html; charset=UTF-8');
            require \base_path('app/View/partials/driver_ts_data_detail.php');
        } catch (Throwable $e) { error_log('Driver TS detail failed: '.$e->getMessage()); http_response_code(500); }
    }

    public function file(string $id, string $documentId): void
    {
        try {
            $context=$this->context();
            $detail=$context === null ? null : $context[3]->detail((int)$id);
            if ($detail === null) { http_response_code(404); return; }
            $doc=null;
            foreach ($detail['files'] as $item) if ((int)$item['id']===(int)$documentId) { $doc=$item; break; }
            if ($doc === null) { http_response_code(404); return; }
            $path=DocumentService::resolveStoredPath($context[0],(string)$doc['relative_path']);
            if ($path === null) { http_response_code(404); return; }
            $name=DocumentService::safeDownloadName((string)$doc['original_name']);
            $mime=DocumentService::responseMime($path);
            $inline=($_GET['view']??'')==='1' && in_array($mime,['application/pdf','image/jpeg','image/png','image/webp','image/gif','text/plain'],true);
            header('Content-Type: '.$mime);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: '.($inline?'inline':'attachment').'; filename*=UTF-8\'\''.rawurlencode($name));
            header('Content-Length: '.filesize($path));
            readfile($path);
        } catch (Throwable $e) { error_log('Driver TS file failed: '.$e->getMessage()); http_response_code(500); }
    }

    public function archive(string $id): void
    {
        $archivePath=null; $workbookPath=null;
        try {
            $context=$this->context();
            $detail=$context === null ? null : $context[3]->detail((int)$id);
            if ($detail === null) { http_response_code(404); return; }
            $workbookPath=DriverTsDataService::workbook(DriverTsDataService::tableRows($detail));
            $archivePath=tempnam(sys_get_temp_dir(),'planex-driver-ts-');
            if ($archivePath === false) throw new RuntimeException('Не удалось создать архив.');
            $zip=new ZipArchive();
            if ($zip->open($archivePath,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Не удалось открыть архив.');
            $zip->addFile($workbookPath,'Данные водителя и ТС.xlsx');
            $names=DriverTsDataService::archiveDocumentNames($detail);
            foreach ($detail['files'] as $index=>$doc) {
                $path=DocumentService::resolveStoredPath($context[0],(string)$doc['relative_path']);
                if ($path===null) throw new RuntimeException('Один из файлов исполнителя недоступен.');
                if (!$zip->addFile($path,$names[$index])) throw new RuntimeException('Не удалось добавить файл в архив.');
            }
            if (!$zip->close()) throw new RuntimeException('Не удалось завершить архив.');
            header('Content-Type: application/zip');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="driver-ts.zip"; filename*=UTF-8\'\''.rawurlencode(DriverTsDataService::archiveDownloadName($detail)));
            header('Content-Length: '.filesize($archivePath));
            readfile($archivePath);
        } catch (Throwable $e) { error_log('Driver TS archive failed: '.$e->getMessage()); if (!headers_sent()) http_response_code(500); }
        finally { if ($archivePath !== null) @unlink($archivePath); if ($workbookPath !== null) @unlink($workbookPath); }
    }
}
