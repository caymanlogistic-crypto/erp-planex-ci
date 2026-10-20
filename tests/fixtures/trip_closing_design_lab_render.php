<?php
declare(strict_types=1);

// Deterministic, synthetic, DB-free fixture for the closing documents screen.
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
function current_app_path(): string { return '/company/logistics/closing-documents'; }
function csrfToken(): string { return 'DESIGN-LAB-REDACTED'; }
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="DESIGN-LAB-REDACTED">'; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$_SESSION = ['role_code'=>'company_owner', 'company_id'=>0, 'user_id'=>1, 'user_name'=>'Иван Петров'];
$managerView=($argv[1]??'')==='manager';
\App\Security\AccessRuntime::setCurrent($managerView
    ? \App\Security\EffectiveAccess::member(2,0,['company.trips.waste'=>true],['waste_trip'=>'own'])
    : \App\Security\EffectiveAccess::systemOwner(1,0,array_column(\App\Security\PageRegistry::companyPages(), 'key')));
$company = ['name'=>'ООО «ПЛАНЭКС»'];
$config = ['app'=>['app_name'=>'ERP PLANEX']];
$db = new stdClass();
$pageTitle = 'Сбор закрывающих документов';
$pageContext = 'Рейсы › Сбор закрывающих документов';
$topbarCrumbs = [['label'=>'Рейсы','url'=>null],['label'=>'Сбор закрывающих документов','url'=>null]];
$success = $flashError = $error = null;
$schemaReady = true;
$_GET = [];

$carriers = [
    'ИП Миронова Ольга Сергеевна', 'ООО «Северный транспорт»', 'ИП Данилов Павел Викторович',
    'ООО «Горизонт Логистик»', 'ИП Беляев Артём Игоревич', 'ООО «Транзит»',
    'ИП Ковалёва Марина Андреевна', 'ООО «ЭкоМаршрут»', 'ИП Сидоров Константин Львович',
];
$managers = ['Петрова Анна Сергеевна', 'Орлов Максим Ильич', 'Соколова Мария Викторовна'];
$slots = array_keys(\App\Service\WasteTripClosingService::SLOTS);
$trips = [];
for ($index = 0; $index < 35; $index++) {
    $businessId = 322 - $index;
    $documents = [];
    $filled = $index === 25 ? 4 : (in_array($index, [31, 32], true) ? 3 : 0);
    foreach (array_slice($slots, 0, $filled) as $position => $slot) {
        $documents[] = [
            'id' => 1000 + $index * 10 + $position,
            'slot_code' => $slot,
            'original_name' => match ($slot) {
                'contract' => 'Подписанная_заявка_'.$businessId.'.pdf',
                'invoice' => 'Счёт_'.$businessId.'.pdf',
                'act' => 'Акт_'.$businessId.'.pdf',
                default => 'Путевой_лист_'.$businessId.'.pdf',
            },
            'custom_title' => null,
        ];
    }
    $trips[] = [
        'id' => $index + 1,
        'business_id' => $businessId,
        'status' => in_array($index, [10, 11], true) ? 'in_progress' : 'completed',
        'actual_end_date' => in_array($index, [10, 11], true) ? null : ($index >= 30 ? '2026-09-25' : '2026-10-'.str_pad((string)(6 - ($index % 6)), 2, '0', STR_PAD_LEFT)),
        'carrier_name' => $carriers[$index % count($carriers)],
        'manager_name' => $managers[$index % count($managers)],
        'manager_membership_id' => 71 + $index % count($managers),
        'legal_entity_code' => $index % 7 === 5 ? 'mg_logistic' : 'planex',
        'recipient_email' => $index % 7 === 5 ? 'documents@example.test' : 'office@example.test',
        'documents' => $documents,
        'transport' => ['units' => match ($index) {
            0 => [['label'=>'Тягач','brand'=>'КамАЗ','plate'=>'А111АА','has_card'=>false]],
            1 => [['label'=>'Тягач','brand'=>'КамАЗ','plate'=>'В222ВВ','has_card'=>true]],
            2 => [['label'=>'Тягач','brand'=>'КамАЗ','plate'=>'С333СС','has_card'=>true],['label'=>'Полуприцеп','brand'=>'Тонар','plate'=>'Е444ЕЕ','has_card'=>false]],
            3 => [['label'=>'Тягач','brand'=>'КамАЗ','plate'=>'К555КК','has_card'=>true],['label'=>'Полуприцеп','brand'=>'Тонар','plate'=>'М666ММ','has_card'=>true]],
            default => [],
        }],
        'paid_bank_transactions' => [],
        'review_state' => 'new',
        'review_version' => 1,
    ];
}
$reportRows = \App\Service\WasteTripClosingReportService::rows($trips, '2026-10-13');
$completionQuarters=['2026-Q4'=>[4,'26'],'2026-Q3'=>[3,'26']];
$responsibleUsers = [
    ['membership_id'=>71,'full_name'=>$managers[0],'login'=>'anna'],
    ['membership_id'=>72,'full_name'=>$managers[1],'login'=>'maxim'],
    ['membership_id'=>73,'full_name'=>$managers[2],'login'=>'maria'],
    ['membership_id'=>74,'full_name'=>'','login'=>'idle.manager'],
];
$canFilterManagers = !$managerView;
$canCreateInvoice = false;
$viewStateKey = 'fixture:closing-registry';

ob_start();
require $root.'/app/View/pages/company_waste_trip_closing_registry.php';
$content = (string)ob_get_clean();
ob_start();
require $root.'/app/View/layouts/main.php';
echo (string)ob_get_clean();
