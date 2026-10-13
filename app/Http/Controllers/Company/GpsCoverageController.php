<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\GpsCoverageService;
use App\Service\WasteTripLegacySourceService;
use PDO;
use Throwable;

final class GpsCoverageController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    private function source(): PDO
    {
        $pdo=WasteTripLegacySourceService::connectionFromEnvironment();
        if (!$pdo) throw new \RuntimeException('База Фрегат не настроена.');
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public function index(): void
    {
        requireRole(['company_owner']);
        $pageTitle='Покрытие GPS'; $pageContext='Рейсы › Покрытие GPS';
        $pageStylesheets=['/assets/css/route-executor-review-workflow.css','/assets/css/route-executor-header.css','/assets/css/route-executor-list-v016.css','/assets/css/gps-coverage.css'];
        $pageScripts=['/assets/js/gps-coverage.js','/assets/js/gps-route-listbox.js'];
        $data=['rows'=>[],'total'=>0,'page'=>1,'source_at'=>null];$error=null;
        try{$data=GpsCoverageService::page($this->source(),max(1,(int)($_GET['page']??1)));}
        catch(Throwable $e){error_log('GPS coverage index: '.$e->getMessage());$error='Не удалось получить рейсы из базы Фрегат.';}
        ob_start();require base_path('app/View/pages/company_gps_coverage.php');$content=(string)ob_get_clean();
        $config=$this->config;$db=$this->db;require base_path('app/View/layouts/main.php');
    }

    public function flight(int|string $id): void
    {
        requireRole(['company_owner']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        try {
            $result=GpsCoverageService::flight($this->source(),(int)$id,(int)($_GET['minutes']??15));
            echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        } catch(\InvalidArgumentException $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
        catch(Throwable $e){error_log('GPS coverage flight: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'Данные GPS временно недоступны.'],JSON_UNESCAPED_UNICODE);}
    }
}
