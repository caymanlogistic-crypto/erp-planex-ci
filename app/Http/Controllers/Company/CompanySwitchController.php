<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\CompanySwitchService;

final class CompanySwitchController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function switch(): void
    {
        if (!isAuthenticated()) {
            redirect_to('/login');
        }
        $companyId = filter_input(INPUT_POST, 'company_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($companyId === false || $companyId === null) {
            http_response_code(422);
            echo 'Выберите компанию.';
            return;
        }
        try {
            (new CompanySwitchService($this->db->connection()))->switch($_SESSION, (int) $companyId);
            session_regenerate_id(true);
            redirect_to('/company/dashboard', 303);
        } catch (\DomainException) {
            http_response_code(403);
            echo 'Нет доступа к выбранной компании.';
        }
    }
}
