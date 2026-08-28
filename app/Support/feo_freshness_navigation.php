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
        register_shutdown_function(static function () use ($freshnessProblem, $role): void {
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'application/json') !== false) return;
            }
            $problem = $freshnessProblem ? 'true' : 'false';
            $owner = $role === 'company_owner' ? 'true' : 'false';
            echo <<<HTML
<script>
(function(){
  var allLinks=document.querySelectorAll('a.nav-sub-item[href$="/company/feo/requests"]');
  allLinks.forEach(function(allLink){
    var sub=allLink.closest('.nav-sub');
    var parent=sub ? sub.previousElementSibling : null;
    if(!sub || !parent) return;
    var meta=document.querySelector('meta[name="erp-base-path"]');
    var base=meta ? (meta.getAttribute('content')||'') : '';
    var costs=sub.querySelector('a.nav-sub-item[href$="/company/feo/requests/costs"]');
    function ensureLink(attr,path,label){
      var existing=sub.querySelector('['+attr+']');
      if(existing) return existing;
      var link=document.createElement('a');
      link.className='nav-sub-item';
      link.setAttribute(attr,'1');
      link.href=base+path;
      link.innerHTML='<span>'+label+'</span>';
      if(costs) sub.insertBefore(link,costs); else sub.appendChild(link);
      return link;
    }
    var placement=ensureLink('data-feo-placement-nav','/company/feo/requests/map-placement','Размещение на карте');
    var mapLink=ensureLink('data-feo-map-nav','/company/feo/requests/map','Карта');
    var utilizerLink={$owner} ? ensureLink('data-feo-utilizers-nav','/company/feo/utilizers','Утилизаторы') : null;
    var path=location.pathname.replace(/\/+$/,'');
    var target=null;
    if(path.endsWith('/company/feo/requests/map-placement')) target=placement;
    else if(path.endsWith('/company/feo/requests/map')) target=mapLink;
    else if({$owner} && path.endsWith('/company/feo/utilizers')) target=utilizerLink;
    if(target){
      sub.querySelectorAll('.nav-sub-item.is-active').forEach(function(link){link.classList.remove('is-active');});
      target.classList.add('is-active');
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
