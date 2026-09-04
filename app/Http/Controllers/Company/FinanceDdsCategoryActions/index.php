<?php

$pageTitle = 'Финансовая структура';
$pageContext = 'Финансы › Финансовая структура';
$companyId = (int)(getSessionCompanyId() ?? 0);

$company = null;
$categories = [];
$structure = [];
$dbError = null;
$successFlash = $_SESSION['finance_dds_success'] ?? null;
unset($_SESSION['finance_dds_success']);
$errorFlash = $_SESSION['finance_dds_error'] ?? null;
unset($_SESSION['finance_dds_error']);

if ($companyId > 0) {
    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($company && ($company['status'] ?? '') === 'active') {
            $pageContext = 'Финансы › Финансовая структура › Компания: ' . $company['name'];
            $localDb = new \App\Core\Database(companyDatabaseConfig($config, $company));
            $localPdo = $localDb->connection();
            applyLocalMigrations($localPdo);
            $categories = \App\Service\FinanceDdsCategoryService::fetchCategories($localPdo);
            $structure = \App\Service\FinanceStructureService::fetchStructure($localPdo, false);
        }
    } catch (\Throwable $e) {
        $dbError = 'Ошибка при загрузке финансовой структуры: ' . $e->getMessage();
    }
}

ob_start();
require base_path('app/View/pages/company_finance_dds_categories.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
