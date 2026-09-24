<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FeoShareStatisticsService;
use PDO;
use Throwable;

final class FeoShareStatisticsController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        $pageTitle = 'Доля компаний в ФЭО';
        $pageContext = 'Статистика › Доля компаний в ФЭО';
        $statistics = null;
        $dbError = null;
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            $dbError = 'Компания не выбрана.';
        } else {
            try {
                $central = $this->db->connection();
                $query = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
                $query->execute([$companyId]);
                $company = $query->fetch(PDO::FETCH_ASSOC);
                if (!$company) {
                    $dbError = 'Компания недоступна.';
                } else {
                    $local = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
                    if (!FeoShareStatisticsService::schemaReady($local)) {
                        $dbError = 'Раздел ожидает обновления схемы статистики ФЭО (145).';
                    } else {
                        $statistics = FeoShareStatisticsService::dashboard($local);
                        if ($statistics['snapshots'] === []) {
                            $statistics = null;
                            $dbError = 'Для компании пока нет сохранённых полных срезов ФЭО.';
                        } else {
                            $pageContext .= ' › Компания: '.(string)$company['name'];
                        }
                    }
                }
            } catch (Throwable $error) {
                error_log('feo share statistics failed: '.$error->getMessage());
                $dbError = 'Не удалось загрузить статистику ФЭО.';
            }
        }
        ob_start();
        require base_path('app/View/pages/company_feo_share_statistics.php');
        $content = (string)ob_get_clean();
        $pageStylesheets = ['/assets/css/manager-statistics.css', '/assets/css/feo-share-statistics.css'];
        $pageScripts = ['/assets/js/feo-share-statistics.js'];
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }
}
