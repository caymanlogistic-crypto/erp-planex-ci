<?php
verifyCsrfRequest();
$companyId = (int)(getSessionCompanyId() ?? 0);
try {
    if ($companyId <= 0) throw new RuntimeException('Компания не найдена.');
    $central = $db->connection();
    $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) throw new RuntimeException('Компания не найдена или неактивна.');
    $local = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($local);
    \App\Service\FinanceStructureDeletionService::deleteDdsCategory($local, (int)($_POST['id'] ?? 0));
    $_SESSION['finance_dds_success'] = 'Статья ДДС удалена.';
} catch (Throwable $e) {
    $_SESSION['finance_dds_error'] = $e->getMessage();
}
redirect_to('/company/finance/settings/dds-categories');
