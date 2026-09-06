<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;

final class ResponsibleAssignmentController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {
    }

    public function index(): void
    {
        $config = $this->config;
        $db = $this->db;

        require base_path('app/Http/Controllers/Company/ResponsibleAssignmentActions/index.php');
    }

    public function reassign(): void
    {
        $config = $this->config;
        $db = $this->db;

        require base_path('app/Http/Controllers/Company/ResponsibleAssignmentActions/reassign.php');
    }

    public function assignments(): void
    {
        $config = $this->config;
        $db = $this->db;

        require base_path('app/Http/Controllers/Company/ResponsibleAssignmentActions/assignments.php');
    }

    public function explicitAssignment(): void
    {
        $config = $this->config;
        $db = $this->db;

        require base_path('app/Http/Controllers/Company/ResponsibleAssignmentActions/explicit_assignment.php');
    }
}
