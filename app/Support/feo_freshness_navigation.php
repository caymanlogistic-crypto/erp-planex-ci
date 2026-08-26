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

        $freshnessProblem = false;
        try {
            $service = new FeoRequestService($config, $centralDb);
            $company = $service->loadCompany($companyId);
            if ($company && ($company['status'] ?? '') === 'active') {
                $pdo = $service->localPdo($company);
                if ($service->moduleReady($pdo)) {
                    $freshness = $service->freshnessState($pdo);
                    $freshnessProblem = !empty($freshness['is_problem']);
                }
            }
        } catch (\Throwable) {
            $freshnessProblem = false;
        }

        $registered = true;
        register_shutdown_function(static function () use ($freshnessProblem): void {
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'application/json') !== false) return;
            }
            $problem = $freshnessProblem ? 'true' : 'false';
            echo <<<HTML
<script>
(function(){
  var allLinks=document.querySelectorAll('a.nav-sub-item[href$="/company/feo/requests"]');
  allLinks.forEach(function(allLink){
    var sub=allLink.closest('.nav-sub');
    var parent=sub ? sub.previousElementSibling : null;
    if(!sub || !parent) return;
    if(!sub.querySelector('[data-feo-placement-nav]')){
      var meta=document.querySelector('meta[name="erp-base-path"]');
      var base=meta ? (meta.getAttribute('content')||'') : '';
      var link=document.createElement('a');
      link.className='nav-sub-item';
      link.setAttribute('data-feo-placement-nav','1');
      link.href=base+'/company/feo/requests/map-placement';
      link.innerHTML='<span>Размещение на карте</span>';
      sub.appendChild(link);
    }
    var placement=sub.querySelector('[data-feo-placement-nav]');
    if(placement && /\/company\/feo\/requests\/map-placement\/?$/.test(location.pathname)){
      allLink.classList.remove('is-active');
      placement.classList.add('is-active');
      sub.classList.add('is-open');
      parent.classList.add('is-open','is-active');
    }
    if({$problem} && !parent.querySelector('[data-feo-freshness-badge]')){
      var badge=document.createElement('span');
      badge.className='nav-count is-alert';
      badge.setAttribute('data-feo-freshness-badge','1');
      badge.title='Требуется обновить заявки ФЭО: последняя активная загрузка старше 7 дней или отсутствует.';
      badge.textContent='1';
      var chevron=parent.querySelector('.nav-chevron');
      if(chevron) parent.insertBefore(badge,chevron); else parent.appendChild(badge);
    }
  });
}());
</script>
HTML;
        });
    }
}
