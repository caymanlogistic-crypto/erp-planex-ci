<?php

use App\Core\Database;
use App\Service\FeoRequestService;

if (!function_exists('registerFeoFreshnessNavigation')) {
    function registerFeoFreshnessNavigation(array $config, Database $centralDb): void
    {
        static $registered = false;
        if ($registered || PHP_SAPI === 'cli') return;
        $role = (string)($_SESSION['role_code'] ?? '');
        if (!in_array($role, ['company_owner','senior_logist','logist'], true)) return;
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) return;

        try {
            $service = new FeoRequestService($config, $centralDb);
            $company = $service->loadCompany($companyId);
            if (!$company || ($company['status'] ?? '') !== 'active') return;
            $pdo = $service->localPdo($company);
            if (!$service->moduleReady($pdo)) return;
            $freshness = $service->freshnessState($pdo);
            if (empty($freshness['is_problem'])) return;
        } catch (\Throwable) {
            return;
        }

        $registered = true;
        register_shutdown_function(static function (): void {
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'application/json') !== false) return;
            }
            echo <<<'HTML'
<script>
(function(){
  var links=document.querySelectorAll('a.nav-sub-item[href*="/company/feo/requests"]');
  links.forEach(function(link){
    var sub=link.closest('.nav-sub');
    var parent=sub ? sub.previousElementSibling : null;
    if(!parent || parent.querySelector('[data-feo-freshness-badge]')) return;
    var badge=document.createElement('span');
    badge.className='nav-count is-alert';
    badge.setAttribute('data-feo-freshness-badge','1');
    badge.title='Требуется обновить заявки ФЭО: последняя активная загрузка старше 7 дней или отсутствует.';
    badge.textContent='1';
    var chevron=parent.querySelector('.nav-chevron');
    if(chevron) parent.insertBefore(badge,chevron); else parent.appendChild(badge);
  });
}());
</script>
HTML;
        });
    }
}
