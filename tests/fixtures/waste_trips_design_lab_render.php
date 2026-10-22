<?php
declare(strict_types=1);

// Deterministic, DB-free fixture for the current Waste Trips page.
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) require_once $file;
    }
});
function base_path(string $path = ''): string { global $root; return $root.($path !== '' ? '/'.ltrim($path, '/') : ''); }
function app_url(string $path): string { return $path; }
function app_base_path(): string { return ''; }
function current_app_path(): string { return '/company/trips/departures'; }
function csrfToken(): string { return 'DESIGN-LAB-REDACTED'; }
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="DESIGN-LAB-REDACTED">'; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ui_date(string $value): string { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date ? $date->format('d.m.Y') : $value; }

$_SESSION = ['role_code'=>'company_owner', 'company_id'=>0, 'user_id'=>1, 'user_name'=>'Иван Петров'];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(
    1,
    0,
    array_column(\App\Security\PageRegistry::companyPages(), 'key')
));
$_GET = $_GET ?? [];
$company = ['name'=>'ООО «ПЛАНЭКС»'];
$config = ['app'=>['app_name'=>'ERP PLANEX']];
$db = new stdClass();
$pageTitle = 'Перевозка отходов';
$pageContext = 'Рейсы › Перевозка отходов';
$dbError = $syncResult = $formError = $formSuccess = null;
$old = [];
$currentMembershipId = 1;
$isFinanceRealm = true;
$clients = [
    ['id'=>1,'name'=>'ООО «Вектор»'], ['id'=>2,'name'=>'ООО «Магистраль»'],
    ['id'=>3,'name'=>'ООО «Север»'], ['id'=>4,'name'=>'ООО «Регион»'],
    ['id'=>5,'name'=>'ООО «Горизонт»'], ['id'=>6,'name'=>'ООО «Контур»'],
];
$responsibleUsers = [
    ['membership_id'=>1,'full_name'=>'Иван Петров'],
    ['membership_id'=>2,'full_name'=>'Анна Соколова'],
    ['membership_id'=>3,'full_name'=>'Мария Волкова'],
    ['membership_id'=>4,'full_name'=>'Олег Миронов'],
    ['membership_id'=>5,'full_name'=>'Елена Белова'],
    ['membership_id'=>6,'full_name'=>'Денис Орлов'],
];
$routeExecutors = [];
for ($executorId = 1; $executorId <= 24; $executorId++) {
    $routeExecutors[] = ['id'=>$executorId,
        'contractor_name'=>$executorId%2?'ИП Сергеев '.$executorId:'ООО «Транзит '.$executorId.'»',
        'driver_name'=>$executorId%2?'Сергеев Алексей Иванович':'Орлов Денис Павлович'];
}
$utilizers = [
    ['id'=>1,'short_name'=>'ЭКО+','full_name'=>'ЭКО+'],
    ['id'=>2,'short_name'=>'ПРОМЭКО','full_name'=>'ПРОМЭКО'],
    ['id'=>3,'short_name'=>'РЕЦИКЛ','full_name'=>'РЕЦИКЛ'],
    ['id'=>4,'short_name'=>'ЭКОПОЛИС','full_name'=>'ЭКОПОЛИС'],
    ['id'=>5,'short_name'=>'ВТОРМЕТ','full_name'=>'ВТОРМЕТ'],
    ['id'=>6,'short_name'=>'ТЕХУТИЛЬ','full_name'=>'ТЕХУТИЛЬ'],
    ['id'=>7,'short_name'=>'СЕВЕРЭКО','full_name'=>'СЕВЕРЭКО'],
    ['id'=>8,'short_name'=>'ФАКЕЛ','full_name'=>'ФАКЕЛ'],
];

$descriptions = ['Красноярск выполнено 25.09','Красноярск выполнено 17.09','Красноярск выполнено 23.09',
    'Новосибирск 3,555 тн','Сборный рейс 7,5 тн','Поставка 7,6 тн','Набережные Челны 5,5 тн',
    'Ростов 10 тн','Иркутск 20,3 тн','Красноярск сбор 22.09','Томск 20 тн','Новокузнецк 25 тн'];
$statuses = ['completed','completed','completed','planned_route','planned_route','executor_assigned',
    'executor_assigned','in_progress','planned_route','completed','planned_route','planned_route'];
$trips = [];
for ($i = 0; $i < 38; $i++) {
    $index = $i % count($descriptions);
    $status = $statuses[$index];
    $hasExecutor = !in_array($status, ['planned_route'], true);
    $amount = 9789 + (($i * 27341) % 750000);
    $payment = static fn(int $value, int $days, string $daysKind = 'calendar'): array => [
        'amount'=>(string)$value,'condition_type'=>'after_end',
        'days_count'=>$days,'days_kind'=>$daysKind,'specific_due_date'=>null,
        'payment_method'=>'cashless','payment_type'=>null,'vat_rate'=>null,'condition_comment'=>'',
    ];
    $trips[] = [
        'id'=>$i+1,'business_id'=>301-$i,'comment'=>$descriptions[$index],
        'status'=>$status,'planned_start_date'=>null,
        'planned_start_date_from'=>$i < 3?'2026-09-24':($i===5?'2026-10-02':($i===6?'2026-09-28':($i===17?'2026-09-21':null))),
        'planned_start_date_to'=>$i < 3?'2026-09-25':($i===17?'2026-09-25':null),
        'planned_finish_date'=>null,
        'actual_start_date'=>in_array($status,['completed','in_progress'],true)?'2026-09-24':null,
        'actual_end_date'=>$status==='completed'?'2026-09-25':null,
        'source_system'=>$i < 3?'legacy_sync':'erpv2','legacy_flight_id'=>$i < 3?301-$i:null,
        'legal_entity_code'=>'planex',
        'legacy_last_checked_at'=>'2026-09-27 09:00:00','legacy_last_success_at'=>'2026-09-27 09:00:00',
        'route_executor_id'=>$hasExecutor?1:null,'lock_version'=>1,
        'client_id'=>1,'client_name'=>'ООО «Вектор»','responsible_membership_id'=>$i%4===0?2:1,
        'destination_type'=>'utilizer','utilizer_id'=>1,'planned_start_mode'=>'exact',
        'requests'=>[['zayavka_id'=>1001+$i,'naim_oo_gruzootpravitel'=>'ООО «Город»',
            'mno_adres_pogruzki'=>'г. Красноярск, ул. Центральная, 1',
            'naim_otkhoda_fkko'=>'Отходы смешанные','snapshot_mass_kg'=>251+($i*1471)%25400]],
        'executor_contractor_name'=>$hasExecutor?'ИП Сергеев Алексей Иванович':'',
        'executor_driver_names'=>$hasExecutor?'Сергеев Алексей Иванович':'',
        'executor_vehicle_label'=>$hasExecutor?'ISUZU А123ВС77':'',
        'request_count'=>1+($i*7)%35,'snapshot_total_mass_kg'=>251+($i*1471)%25400,
        'general_payments'=>[$payment($amount,3)],
        'customer_payments'=>$hasExecutor?[$payment((int)($amount*0.75),4,'working')]:[],
        'carrier_payments'=>($i%9===0)?[$payment((int)($amount*0.5),7,'working')]:[],
        'responsible_name'=>($i%4===0?'Соколова Анна':'Петров Иван'),
        'financial_model_version'=>2,
    ];
}

$tripStatusCounts = [''=>count($trips)];
foreach ($trips as $trip) {
    $status = (string)$trip['status'];
    $tripStatusCounts[$status] = ($tripStatusCounts[$status] ?? 0) + 1;
}
$trackerNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$trackerLastPoints = [];
$trackerAges = [2, 13 * 60 + 2, 12 * 24 * 60 + 13 * 60 + 2];
$trackerIndex = 0;
foreach ($trips as $trip) {
    if ($trip['status'] !== 'in_progress') continue;
    $trackerLastPoints[(int)$trip['id']] = gmdate('Y-m-d H:i:s', $trackerNow->getTimestamp() - $trackerAges[$trackerIndex++ % count($trackerAges)] * 60);
}
$statusOrder = ['planned_route'=>0,'executor_assigned'=>1,'in_progress'=>2,'completed'=>3];
usort($trips, static fn(array $a,array $b):int => ($statusOrder[$a['status']]??4)<=>($statusOrder[$b['status']]??4));
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $trips = array_values(array_filter($trips, static fn(array $trip): bool => $trip['status'] === $_GET['status']));
}

ob_start();
require $root.'/app/View/pages/company_waste_trips.php';
$content = (string)ob_get_clean();
// The accepted sandbox varies the display badge synthetically without changing
// the finance domain state of those sample rows.
$content = preg_replace_callback('/<tr class="waste-row" data-waste-view="(\d+)"[^>]*>.*?<\/tr>/s',
    static function (array $match): string {
        if (((int)$match[1] - 1) % 4 !== 1) return $match[0];
        return str_replace('is-planex">ООО «Планэкс»', 'is-mg-logistic">ООО «МГ Логистик»', $match[0]);
    }, $content);
foreach ($trips as $trip) {
    $tripId = (int)$trip['id'];
    ob_start();
    require $root.'/app/View/partials/company_waste_trip_modal_view.php';
    $viewHtml = (string)ob_get_clean();
    $content .= '<template data-lab-modal="view" data-lab-trip="'.$tripId.'">'.$viewHtml.'</template>';
    $editOld = [];
    ob_start();
    require $root.'/app/View/partials/company_waste_trip_modal_edit.php';
    $editHtml = (string)ob_get_clean();
    $content .= '<template data-lab-modal="edit" data-lab-trip="'.$tripId.'">'.$editHtml.'</template>';
}
ob_start();
require $root.'/app/View/layouts/main.php';
$html = (string)ob_get_clean();
$badges = [
    'Регистрация времени загрузки'=>'11',
    'Заявки ФЭО'=>'1',
    'Стоимость заявок'=>'1',
    'Выписки со счёта'=>'1',
    'Закрывающие документы'=>'21',
];
foreach ($badges as $label => $count) {
    $html = str_replace('<span class="nav-label">'.$label.'</span>',
        '<span class="nav-label">'.$label.'</span><span class="nav-count is-alert">'.$count.'</span>', $html);
    $html = str_replace('<span>'.$label.'</span></a>',
        '<span>'.$label.'</span><span class="nav-count is-alert">'.$count.'</span></a>', $html);
}
echo $html;
