<?php

// Compatibility layer for focused UI/runtime corrections.
if (PHP_SAPI !== 'cli') {
    $path = current_app_path();

    // Date pickers submit DD.MM.YYYY while DATE columns require YYYY-MM-DD.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('~^/company/drivers/\d+/(?:modal-edit|edit)$~', $path)) {
        foreach (['passport_issue_date', 'license_issue_date', 'passport_issue_date', 'license_expire_date'] as $field) {
            if (!isset($_POST[$field])) continue;
            $value = trim((string) $_POST[$field]);
            if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $m)) {
                $day = (int) $m[1]; $month = (int) $m[2]; $year = (int) $m[3];
                if (checkdate($month, $day, $year)) {
                    $_POST[$field] = sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('~^/company/vehicle-sets(?:/create|/\d+/modal-edit)$~', $path)
        && !empty($_POST['units']) && is_array($_POST['units'])) {
        foreach ($_POST['units'] as $role => $unit) {
            if (!is_array($unit)) continue;
            foreach (['capacity_tons', 'volume_m3'] as $field) {
                if (!array_key_exists($field, $unit)) continue;
                $raw = preg_replace('/\s+/u', '', trim((string) $unit[$field])) ?? '';
                $_POST['units'][$role][$field] = $raw === '' ? '' : str_replace(',', '.', $raw);
            }
            if (array_key_exists('diagnostic_card_date', $unit)) {
                $value = trim((string) $unit['diagnostic_card_date']);
                if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $m)) {
                    $day = (int) $m[1]; $month = (int) $m[2]; $year = (int) $m[3];
                    if (checkdate($month, $day, $year)) {
                        $_POST['units'][$role]['diagnostic_card_date'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    }
                }
            }
        }
    }

    ob_start(static function (string $html) use ($config): string {
        if (!str_contains($html, '</body>')) return $html;

        // The old menu slot is now the unified financial-structure editor.
        $html = str_replace('<span class="nav-label">Статьи ДДС</span>', '<span class="nav-label">Финансовая структура</span>', $html);

        // Employee money is the only user-facing owner of physical company funds.
        // Replace the retired legacy money-account navigation slot rather than
        // exposing a second financial subsystem to the user.
        $employeeActive = str_starts_with(current_app_path(), '/company/finance/employee-payments') ? ' is-active' : '';
        $employeeHref = e(app_url('/company/finance/employee-payments'));
        $employeeItem = '<a class="nav-item' . $employeeActive . '" href="' . $employeeHref . '">' .
            '<svg class="nav-icon" viewBox="0 0 16 16" fill="none"><circle cx="5.5" cy="5" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M1.5 13.5C1.5 10.8 3.5 8.8 5.5 8.8C7.5 8.8 9.5 10.8 9.5 13.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M10 5.5H14M12 3.5V7.5M10 11.5H14" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>' .
            '<span class="nav-label">Взаиморасчёты с сотрудниками</span></a>';

        // Cash is a canonical visible finance page again. Navigation is owned by PageRegistry;
        // compatibility code must not replace or remove it.

        // Normalize the employee-money workspace to the current business model.
        // Historical database facts remain immutable; only their presentation is
        // translated from the retired implementation vocabulary.
        $financeVocabulary = [
            'Выплаты сотрудникам' => 'Взаиморасчёты с сотрудниками',
            'Лицевой счёт сотрудника: все выплаты из кассы и возвраты компании. Прямые выплаты с расчётного счёта не используются.' => 'Движение средств сотрудника: получено от клиентов, оплачено за компанию и передано другим сотрудникам.',
            'Касса выведена из рабочего контура. Исторические операции сохранены в финансовой истории.' => '',
            'Выдача из кассы' => 'Получено сотрудником',
            'Возврат в кассу' => 'Передано сотрудником',
            'Разнесение технической кассы.' => 'Внутренний расчёт по операции.',
            'Разнесение технической кассы' => 'Внутренний расчёт по операции',
            'Технический перевод кассы' => 'Внутренний перевод средств',
            'техническую «Основная касса»' => 'внутренний расчёт',
            'технической «Основная касса»' => 'внутреннего расчёта',
            'Основная касса' => 'Средства компании',
        ];
        $html = str_replace(array_keys($financeVocabulary), array_values($financeVocabulary), $html);

        // Finance content vocabulary still normalizes historical wording. Sidebar
        // visibility is owned by PageRegistry; hidden legacy pages are not rewritten here.

        // ERPv2 lives below /erpv2. A few legacy partials still emit root-relative
        // document links; scope the compatibility rewrite to ERPv2 so /erp is untouched.
        if (app_base_path() === '/erpv2') {
            $html = str_replace('href="/company/documents', 'href="/erpv2/company/documents', $html);
        }

        $src = app_url('/assets/js/driver-phone-optional.js') . '?v=' . filemtime(base_path('public/assets/js/driver-phone-optional.js'));
        $extraScripts = '<script src="' . e($src) . '"></script>';
        if (str_starts_with(current_app_path(), '/company/finance/employee-payments')) {
            $employeeExpenseSrc = app_url('/assets/js/finance-employee-personal-expense.js') . '?v=' . filemtime(base_path('public/assets/js/finance-employee-personal-expense.js'));
            $extraScripts .= '<script src="' . e($employeeExpenseSrc) . '"></script>';
        }
        $baseFix = <<<'HTML'
<script>
(function(){
  function normalizeFinanceText(root){
    if(window.location.pathname.indexOf('/company/finance')===-1)return;
    var scope=root===document?document.body:root;
    if(!scope)return;
    var walker=document.createTreeWalker(scope,NodeFilter.SHOW_TEXT);
    var node;
    while((node=walker.nextNode())){
      if(node.parentElement&&node.parentElement.closest('.sidebar'))continue;
      var value=node.nodeValue||'';
      if(!/касс/iu.test(value))continue;
      value=value
        .replace(/Лицевой счёт сотрудника: все выплаты из кассы и возвраты компании\. Прямые выплаты с расчётного счёта не используются\./giu,'Движение средств сотрудника: получено от клиентов, оплачено за компанию и передано другим сотрудникам.')
        .replace(/Выдача из кассы/giu,'Получено сотрудником')
        .replace(/Возврат в кассу/giu,'Передано сотрудником')
        .replace(/Разнесение технической кассы\.?/giu,'Внутренний расчёт по операции.')
        .replace(/Технический перевод кассы/giu,'Внутренний перевод средств')
        .replace(/Основная касса/giu,'Средства компании')
        .replace(/Основной кассы/giu,'Средств компании')
        .replace(/Основную кассу/giu,'Средства компании')
        .replace(/касс\p{L}*/giu,'средства компании');
      node.nodeValue=value;
    }
  }
  function normalizeFinanceNavigation(root,base){
    if(window.location.pathname.indexOf('/company/finance')===-1)return;
    var old=[];
    if(root.matches&&root.matches('a[href*="/company/finance/cash"]'))old.push(root);
    root.querySelectorAll('a[href*="/company/finance/cash"]').forEach(function(link){old.push(link);});
    old.forEach(function(link){
      var existing=document.querySelector('a[href*="/company/finance/employee-payments"]');
      if(existing&&existing!==link){link.remove();return;}
      link.setAttribute('href',(base||'')+'/company/finance/employee-payments');
      link.classList.toggle('is-active',window.location.pathname.indexOf('/company/finance/employee-payments')!==-1);
      var label=link.querySelector('.nav-label');
      if(label)label.textContent='Взаиморасчёты с сотрудниками';
      link.querySelectorAll('.nav-count').forEach(function(node){node.remove();});
    });
  }
  function fix(root){
    if(!root||!root.querySelectorAll)return;
    var base=window.getErpBasePath?window.getErpBasePath():'';
    var formSelector='#vehicle-set-create-form,#vehicle-set-edit-form,#driver-create-form,#driver-edit-form';
    var forms=[];
    if(root.matches&&root.matches(formSelector))forms.push(root);
    root.querySelectorAll(formSelector).forEach(function(form){forms.push(form);});
    forms.forEach(function(form){
      var action=form.getAttribute('action')||'';
      if(action.indexOf('/company/')===0&&base)form.setAttribute('action',base+action);
    });

    var links=[];
    if(root.matches&&root.matches('a[href^="/company/documents"]'))links.push(root);
    root.querySelectorAll('a[href^="/company/documents"]').forEach(function(link){links.push(link);});
    links.forEach(function(link){
      var href=link.getAttribute('href')||'';
      if(href.indexOf('/company/documents')===0&&base)link.setAttribute('href',base+href);
    });
    normalizeFinanceText(root);
  }
  fix(document);
  new MutationObserver(function(records){records.forEach(function(record){record.addedNodes.forEach(function(node){if(node.nodeType===1)fix(node);});});}).observe(document.body,{childList:true,subtree:true});
}());
</script>
HTML;
        return str_replace('</body>', $extraScripts . $baseFix . '</body>', $html);
    });
}
