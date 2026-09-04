<?php

    $pageTitle = 'Редактирование статьи ДДС';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        echo '<div class="form-alert alert-error">Компания не найдена.</div>';
        return;
    }

    $categoryId = (int) ($_GET['id'] ?? 0);
    if ($categoryId <= 0) {
        echo '<div class="form-alert alert-error">Не указан ID статьи.</div>';
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND status = \'active\'');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$company) {
            echo '<div class="form-alert alert-error">Компания не найдена или неактивна.</div>';
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $category = \App\Service\FinanceDdsCategoryService::findCategory($localPdo, $categoryId);
        if (!$category) {
            echo '<div class="form-alert alert-error">Статья ДДС не найдена.</div>';
            return;
        }

        $parentCategories = \App\Service\FinanceDdsCategoryService::fetchCategories($localPdo);
    } catch (\Throwable $e) {
        echo '<div class="form-alert alert-error">Ошибка загрузки данных.</div>';
        return;
    }

    require base_path('app/View/partials/company_dds_category_form.php');
