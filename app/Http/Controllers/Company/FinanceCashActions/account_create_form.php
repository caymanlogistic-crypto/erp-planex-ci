<?php

    $pageTitle = 'Создание кассы';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        echo '<div class="form-alert alert-error">Компания не найдена.</div>';
        return;
    }

    require base_path('app/View/partials/company_cash_account_form.php');
