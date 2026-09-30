<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;

final class FinanceInvoiceController
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
        require base_path('app/Http/Controllers/Company/InvoiceActions/index.php');
    }

    public function receivedPaid(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/received_paid.php');
    }

    public function receivables(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/receivables.php');
    }

    public function payables(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/payables.php');
    }

    public function createSubmit(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/create_submit.php');
    }

    public function modalView(string $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/modal_view.php');
    }

    public function modalEditForm(string $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/modal_edit_form.php');
    }

    public function modalEditSubmit(string $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/modal_edit_submit.php');
    }

    public function modalDelete(string $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/modal_delete.php');
    }

    public function payCashSubmit(string $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/pay_cash_submit.php');
    }

    public function settlementDateSubmit(string $id, string $operationId): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/settlement_date_submit.php');
    }

    public function routePayments(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/route_payments.php');
    }

    public function obligations(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/obligations.php');
    }

    public function history(int $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/InvoiceActions/history.php');
    }
}
