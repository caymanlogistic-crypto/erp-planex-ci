<?php
/** @var ContractorService $service */
use App\Service\ContractorContactService;

requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Перевозчик';
$pageContext = 'Перевозчики › Компания';

$companyId = $service->getCompanyId();
$archiveError = null;
$grants = [];
$logists = [];
$crewBlocks = [];

if ($companyId <= 0) {
    $company = null;
    $contractor = null;
    $dbError = null;

    ob_start();
    require base_path('app/View/pages/company_contractor_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $company = $service->loadCompany($companyId);

    if (!$company) {
        $company = null;
        $contractor = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Перевозчики › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $contractor = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $localPdo = $service->getLocalPdo($company);

    $contractor = $service->getContractorById($localPdo, (int) $id);

    $accessDenied = null;
    $createdByUser = null;
    $updatedByUser = null;
    if ($contractor) {
        $createdByUser = $localPdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $createdByUser->execute([(int)$contractor['created_by_user_id']]);
        $createdByUser = $createdByUser->fetchColumn() ?: null;
        if (!empty($contractor['updated_by_user_id'])) {
            $updatedByUser = $localPdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $updatedByUser->execute([(int)$contractor['updated_by_user_id']]);
            $updatedByUser = $updatedByUser->fetchColumn() ?: null;
        }
    }

    if ($contractor && !$accessDenied) {
        $pageTitle = 'Перевозчик: ' . $contractor['name'];
    }

    $contacts = [];
    if ($contractor && !$accessDenied) {
        $contacts = ContractorContactService::loadByContractorId($localPdo, (int) $id);
    }

    $taxHistory = [];
    if ($contractor && !$accessDenied) {
        $taxHistory = $localPdo->prepare("SELECT * FROM contractor_tax_history WHERE contractor_id = ? ORDER BY effective_from DESC, id DESC");
        $taxHistory->execute([(int)$id]);
        $taxHistory = $taxHistory->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($contractor && !$accessDenied) {
        try {
            $crewBlockStmt = $localPdo->prepare(
                "SELECT c.id AS crew_id, dvb.id AS block_id, dvb.status AS block_status,
                        d.full_name AS driver_name, d.id AS driver_id,
                        vs.id AS vehicle_set_id,
                        vs.primary_vehicle_unit_id, vs.secondary_vehicle_unit_id
                 FROM crews c
                 JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id = dvb.id
                 JOIN drivers d ON dvb.driver_id = d.id
                 JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                 WHERE c.contractor_id = ? AND c.status != 'archived'
                 ORDER BY d.full_name"
            );
            $crewBlockStmt->execute([(int)$id]);
            $crewBlocks = $crewBlockStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($crewBlocks)) {
                $vehicleUnitIds = [];
                foreach ($crewBlocks as $cb) {
                    if (!empty($cb['primary_vehicle_unit_id'])) {
                        $vehicleUnitIds[] = (int)$cb['primary_vehicle_unit_id'];
                    }
                    if (!empty($cb['secondary_vehicle_unit_id'])) {
                        $vehicleUnitIds[] = (int)$cb['secondary_vehicle_unit_id'];
                    }
                }
                $vehicleUnitIds = array_unique($vehicleUnitIds);
                $vehicleUnitIds = array_values($vehicleUnitIds);

                $plateMap = [];
                if (!empty($vehicleUnitIds)) {
                    $placeholders = implode(',', array_fill(0, count($vehicleUnitIds), '?'));
                    $plateStmt = $localPdo->prepare("SELECT id, plate_number FROM vehicle_units WHERE id IN ($placeholders)");
                    $plateStmt->execute($vehicleUnitIds);
                    while ($row = $plateStmt->fetch(PDO::FETCH_ASSOC)) {
                        $plateMap[$row['id']] = $row['plate_number'];
                    }
                }

                foreach ($crewBlocks as &$cb) {
                    $primaryPlate = $plateMap[$cb['primary_vehicle_unit_id']] ?? null;
                    $secondaryPlate = !empty($cb['secondary_vehicle_unit_id'])
                        ? ($plateMap[$cb['secondary_vehicle_unit_id']] ?? null)
                        : null;
                    $cb['vehicle_plate'] = $primaryPlate ?: 'ТС #' . $cb['vehicle_set_id'];
                    $cb['secondary_plate'] = $secondaryPlate;
                }
                unset($cb);
            }
        } catch (\Exception $e) {
            $crewBlocks = [];
        }
    }

    $grants = [];
    $logists = [];
    $dbError = null;
} catch (\Exception $e) {
    $company = $company ?? null;
    $contractor = null;
    $grants = [];
    $logists = [];
    $crewBlocks = [];
    $dbError = 'Не удалось загрузить перевозчика: ' . $e->getMessage();
}

ob_start();
require base_path('app/View/pages/company_contractor_view.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
