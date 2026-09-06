<?php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\CompanyLandingPageService;

final class CoreLandingController
{
    public function __construct(private readonly array $config, private readonly Database $db)
    {
    }

    public function index(): void
    {
        if (!isAuthenticated()) {
            redirect_to('/login');
        }
        if (($_SESSION['role_code'] ?? '') === 'superadmin') {
            redirect_to('/superadmin/companies');
        }
        try {
            redirect_to((new CompanyLandingPageService($this->db->connection()))->pathForAccess(AccessRuntime::current()));
        } catch (\Throwable) {
            redirect_to('/access-unavailable');
        }
    }

    public function unavailable(): void
    {
        if (!isAuthenticated()) {
            redirect_to('/login');
        }
        if (($_SESSION['role_code'] ?? '') === 'superadmin') {
            redirect_to('/superadmin/companies');
        }
        $pageTitle = 'Нет доступных разделов';
        $pageContext = 'Доступ';
        $content = '<div class="panel"><div class="panel-body"><div class="notice warn">Для вашей роли пока не назначена доступная стартовая страница. Обратитесь к Руководителю компании.</div></div></div>';
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }
}
