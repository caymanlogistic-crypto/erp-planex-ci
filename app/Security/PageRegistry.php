<?php

namespace App\Security;

use InvalidArgumentException;
use LogicException;

/**
 * The only code-owned catalogue of company ERP page permissions.
 *
 * Menu and administration consume pages()/tree(). Protected route files bind
 * their server-owned endpoint patterns through Router's third argument, which
 * registers those mappings on this same registry instance.
 */
final class PageRegistry
{
    /** Permanent tombstones: a retired stable key must never be reused. @var list<string> */
    private const RETIRED_PAGE_KEYS = [];

    /** @var array<string, array<string,mixed>> */
    private array $pages = [];

    /** @var array<string,true> */
    private array $retiredKeys = [];

    /** @var array<string, list<string>> */
    private array $endpoints = [];

    /**
     * @param list<array<string,mixed>>|null $pages
     * @param list<string>|null $retiredPageKeys Test/contract injection only; production uses RETIRED_PAGE_KEYS.
     */
    public function __construct(?array $pages = null, ?array $retiredPageKeys = null)
    {
        foreach ($retiredPageKeys ?? self::RETIRED_PAGE_KEYS as $key) {
            $key = trim((string)$key);
            if ($key === '') {
                throw new InvalidArgumentException('Retired page key must be non-empty.');
            }
            $this->retiredKeys[$key] = true;
        }
        foreach ($pages ?? self::companyPages() as $page) {
            $this->registerPage($page);
        }
    }

    /**
     * @return list<array{key:string,label:string,section:string,group:string,order:int,path:string}>
     */
    public static function companyPages(): array
    {
        return [
            self::definition('company.dashboard', 'Главная', 'Операции', '', 10, '/company/dashboard'),
            self::definition('company.clients', 'Клиенты', 'Операции', '', 20, '/company/clients'),
            self::definition('company.trips.linear', 'Линейные', 'Операции', 'Рейсы', 30, '/company/trips/linear', badgeProvider: 'linear_trips_problem', badgeDisplay: 'self_parent'),
            self::definition('company.trips.waste', 'Отходы', 'Операции', 'Рейсы', 40, '/company/trips/departures', badgeProvider: 'departures_problem', badgeDisplay: 'self_parent'),
            self::definition('company.trips.gps_coverage', 'Покрытие GPS', 'Операции', 'Рейсы', 41, '/company/trips/gps-coverage'),
            self::definition('company.warehouses.stock', 'Заявки на складе', 'Операции', 'Склады', 41, '/company/warehouses/stock', badgeProvider: 'warehouse_stock_problem', badgeDisplay: 'self_parent'),
            self::definition('company.warehouses.addresses', 'Адреса складов', 'Операции', 'Склады', 42, '/company/warehouses/addresses'),
            self::definition('company.documents.request', 'Запросить документы', 'Операции', 'Рейсы', 41, '/company/documents/request'),
            self::definition('company.documents.collection', 'Регистрация времени загрузки', 'Операции', '', 42, '/company/logistics-assistant', badgeProvider: 'trip_completion_problem'),
            self::definition('company.documents.tn_review', 'Проверка ТН', 'Операции', '', 43, '/company/tn-review', badgeProvider: 'tn_review_pending'),
            self::definition('company.documents.trip_closing', 'Сбор закрывающих документов', 'Операции', '', 44, '/company/trips/closing-documents'),
            self::definition('company.documents.trip_closing_registry', 'Закрывающие документы', 'Логисты', '', 280, '/company/logistics/closing-documents', badgeProvider: 'trip_closing_blocked_unloaded'),
            self::definition('company.statistics.work', 'Статистика работы', 'Логисты', '', 290, '/company/statistics/work'),
            self::definition('company.registration_journal', 'Регистрация заявок', 'Операции', 'Журналы', 45, '/company/registration-journal'),
            self::definition('company.feo.requests', 'Все заявки', 'Операции', 'Заявки ФЭО', 50, '/company/feo/requests', badgeProvider: 'feo_freshness', badgeDisplay: 'self_parent'),
            self::definition('company.feo.costs', 'Стоимость заявок', 'Операции', 'Заявки ФЭО', 61, '/company/feo/requests/costs', badgeProvider: 'feo_cost_problem', badgeDisplay: 'self_parent'),
            self::definition('company.feo.map', 'Карта', 'Операции', 'Заявки ФЭО', 70, '/company/feo/requests/map', badgeProvider: 'feo_map_problem', badgeDisplay: 'self_parent'),
            self::definition('company.feo.map_placement', 'Размещение на карте', 'Операции', 'Заявки ФЭО', 60, '/company/feo/requests/map-placement', badgeProvider: 'feo_placement_problem', badgeDisplay: 'self_parent'),
            self::definition('company.feo.utilizers', 'Утилизаторы', 'Операции', 'Заявки ФЭО', 80, '/company/feo/utilizers', badgeProvider: 'feo_utilizer_problem', badgeDisplay: 'self_parent'),
            self::definition('company.feo.transfers', 'Перемещение заявок', 'Операции', 'Заявки ФЭО', 62, '/company/feo/requests/transfers'),
            self::definition('company.feo.weights', 'Изменение веса', 'Операции', 'Заявки ФЭО', 63, '/company/feo/requests/weights'),
            self::definition('company.feo.coordinates', 'Определение координат', 'Операции', 'Заявки ФЭО', 64, '/company/feo/requests/coordinates'),
            self::definition('company.route_executors', 'Исполнители рейса', 'Операции', '', 100, '/company/route-executors'),
            self::definition('company.driver_ts_data', 'Данные Вод и ТС', 'Операции', '', 101, '/company/driver-ts-data', grantable: false),
            self::definition('company.documents.create', 'Создать документ', 'Операции', 'Генератор договоров', 110, '/company/document-generator'),
            self::definition('company.documents.history', 'История документов', 'Операции', 'Генератор договоров', 120, '/company/document-generator/history'),
            self::definition('company.documents.templates', 'Шаблоны договоров', 'Операции', 'Генератор договоров', 130, '/company/document-generator/templates'),
            self::definition('company.max_messages', 'Сообщения MAX', 'Операции', '', 140, '/company/max/messages', navVisible: false, grantable: false, ownerOnly: true),
            self::definition('company.contractors', 'Подрядчики', 'Справочники', '', 200, '/company/contractors'),
            self::definition('company.drivers', 'Водители', 'Справочники', '', 210, '/company/drivers'),
            self::definition('company.vehicle_units', 'ТС', 'Справочники', '', 220, '/company/vehicles', navVisible: false, grantable: false),
            self::definition('company.vehicle_sets', 'ТС', 'Справочники', '', 230, '/company/vehicle-sets'),
            self::definition('company.statistics.managers', 'Работа логистов', 'Операции', 'Статистика', 250, '/company/statistics/managers'),
            self::definition('company.statistics.feo_share', 'Доля компаний в ФЭО', 'Операции', 'Статистика', 260, '/company/statistics/feo-share'),
            self::definition('company.statistics.cargo_balance', 'Баланс заявок', 'Операции', 'Статистика', 270, '/company/statistics/cargo-balance'),
            self::definition('company.finance.dashboard', 'Дашборд', 'Финансы', '', 300, '/company/finance/dashboard'),
            self::definition('company.finance.bank_accounts', 'Выписки со счёта', 'Финансы', '', 310, '/company/finance/bank-accounts', badgeProvider: 'bank_attention'),
            self::definition('company.finance.closing_documents', 'Закрывающие документы', 'Финансы', '', 320, '/company/finance/closing-documents', badgeProvider: 'closing_documents_problem'),
            self::definition('company.finance.cash', 'Касса', 'Финансы', '', 330, '/company/finance/cash', navVisible: false, badgeProvider: 'cash_attention'),
            self::definition('company.finance.invoices', 'Счета', 'Финансы', '', 340, '/company/finance/invoices'),
            self::definition('company.finance.received_paid', 'Получено/оплачено', 'Финансы', '', 345, '/company/finance/received-paid', grantable: false),
            self::definition('company.finance.operations', 'Операции', 'Финансы', '', 350, '/company/finance/operations', navVisible: false),
            self::definition('company.finance.dds_categories', 'Финансовая структура', 'Финансы', 'Настройки', 360, '/company/finance/settings/dds-categories'),
            self::definition('company.finance.payment_calendar', 'Платёжный календарь', 'Финансы', 'Отчёты', 370, '/company/finance/payment-calendar'),
            self::definition('company.finance.cash_flow', 'БДДС', 'Финансы', 'Отчёты', 380, '/company/finance/reports/cash-flow'),
            self::definition('company.finance.management_balance', 'Управленческий баланс', 'Финансы', 'Отчёты', 390, '/company/finance/reports/management-balance'),
            self::definition('company.finance.payment_plan_fact', 'План-факт оплаты', 'Финансы', 'Отчёты', 400, '/company/finance/reports/payment-plan-fact'),
            self::definition('company.finance.matching_rules', 'Правила разнесения', 'Финансы', 'Настройки', 410, '/company/finance/settings/matching-rules'),
            self::definition('company.finance.bank_settings', 'Настройки выписок', 'Финансы', 'Настройки', 420, '/company/bank-statement-settings'),
            self::definition('company.finance.employee_payments', 'Взаиморасчёты с сотрудниками', 'Финансы', '', 430, '/company/finance/employee-payments'),
            self::definition('company.production_calendar', 'Производственный календарь', 'Настройки', '', 500, '/company/misc/production-calendar', grantable: false, ownerOnly: true, badgeProvider: 'production_calendar_warning'),
            self::definition('company.roles_access', 'Роли и доступы', 'Настройки', '', 510, '/company/roles-access', grantable: false, ownerOnly: true),
            self::definition('company.responsible_assignments', 'Переназначение ответственности', 'Настройки', '', 520, '/company/responsible-assignments', landingEligible: false, grantable: false, ownerOnly: true),
            self::definition('company.max_settings', 'MAX — интеграция', 'Настройки', '', 530, '/company/max/settings', landingEligible: false, grantable: false, ownerOnly: true),
            self::definition('company.service_keys', 'Ключи и сервисы', 'Настройки', '', 540, '/company/settings/services', landingEligible: false, grantable: false, ownerOnly: true),
        ];
    }

    /** @return list<string> */
    public function pageKeys(): array
    {
        return array_keys($this->pages);
    }

    /** @return list<string> */
    public function grantablePageKeys(): array
    {
        return array_values(array_map(
            static fn (array $page): string => (string)$page['key'],
            array_filter($this->pages, static fn (array $page): bool => (bool)($page['grantable'] ?? true))
        ));
    }

    /** @return list<array<string,mixed>> */
    public function grantablePages(): array
    {
        return array_values(array_filter($this->pages, static fn (array $page): bool => (bool)($page['grantable'] ?? true)));
    }

    /** @return array<string,array<string,list<array<string,mixed>>>> */
    public function grantableTree(): array
    {
        return $this->treeFrom($this->grantablePages());
    }

    public function isGrantable(string $pageKey): bool
    {
        return isset($this->pages[$pageKey]) && (bool)($this->pages[$pageKey]['grantable'] ?? true);
    }

    /** @return list<string> */
    public function retiredPageKeys(): array
    {
        return array_keys($this->retiredKeys);
    }

    public function isRetired(string $pageKey): bool
    {
        return isset($this->retiredKeys[$pageKey]);
    }

    public function hasPage(string $pageKey): bool
    {
        return isset($this->pages[$pageKey]);
    }

    /** @return array{key:string,label:string,section:string,group:string,order:int,path:string}|null */
    public function page(string $pageKey): ?array
    {
        return $this->pages[$pageKey] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function pages(): array
    {
        return array_values($this->pages);
    }

    /** @return list<array<string,mixed>> */
    public function navigationPages(): array
    {
        return array_values(array_filter($this->pages, static fn (array $page): bool => (bool)($page['nav_visible'] ?? true)));
    }

    /** @return list<array<string,mixed>> */
    public function landingPages(): array
    {
        return array_values(array_filter(
            $this->pages,
            static fn (array $page): bool => (bool)($page['nav_visible'] ?? true) && (bool)($page['landing_eligible'] ?? true)
        ));
    }

    /** @return array<string,array<string,list<array<string,mixed>>>> */
    public function tree(): array
    {
        return $this->treeFrom($this->pages());
    }

    /** @return array<string,array<string,list<array<string,mixed>>>> */
    public function navigationTree(): array
    {
        return $this->treeFrom($this->navigationPages());
    }

    /** @param string|list<string> $pageKeys */
    public function registerEndpoint(string $method, string $path, string|array $pageKeys): void
    {
        $keys = is_array($pageKeys) ? array_values($pageKeys) : [$pageKeys];
        $keys = array_values(array_unique(array_filter($keys, static fn (mixed $key): bool => is_string($key) && $key !== '')));
        if ($keys === []) {
            throw new InvalidArgumentException('Protected endpoint must have at least one page key.');
        }
        foreach ($keys as $key) {
            if (!$this->hasPage($key)) {
                throw new InvalidArgumentException(($this->isRetired($key) ? 'Retired' : 'Unknown') . ' page key: ' . $key);
            }
        }

        $endpointKey = $this->endpointKey($method, $path);
        if (isset($this->endpoints[$endpointKey]) && $this->endpoints[$endpointKey] !== $keys) {
            throw new LogicException('Conflicting page mapping for endpoint ' . $endpointKey);
        }
        $this->endpoints[$endpointKey] = $keys;
    }

    /** @return list<string>|null */
    public function endpointPageKeys(string $method, string $path): ?array
    {
        return $this->endpoints[$this->endpointKey($method, $path)] ?? null;
    }

    /** @return array<string, list<string>> */
    public function endpointMappings(): array
    {
        return $this->endpoints;
    }

    /** @param array<string,mixed> $page */
    private function registerPage(array $page): void
    {
        $key = trim((string)($page['key'] ?? ''));
        if ($key === '' || isset($this->pages[$key])) {
            throw new InvalidArgumentException('Page key must be non-empty and unique.');
        }
        if ($this->isRetired($key)) {
            throw new InvalidArgumentException('Retired page key may not be reused: ' . $key);
        }
        foreach (['label', 'section', 'path'] as $field) {
            if (trim((string)($page[$field] ?? '')) === '') {
                throw new InvalidArgumentException('Page metadata is incomplete for ' . $key);
            }
        }
        if (!str_starts_with((string)$page['path'], '/company/')) {
            throw new InvalidArgumentException('Company page path must start with /company/: ' . $key);
        }
        $page += [
            'group' => '',
            'order' => 0,
            'nav_visible' => true,
            'landing_eligible' => true,
            'grantable' => true,
            'badge_provider' => null,
            'badge_display' => 'self',
            'owner_only' => false,
        ];
        $page['key'] = $key;
        $page['nav_visible'] = (bool)$page['nav_visible'];
        $page['landing_eligible'] = (bool)$page['landing_eligible'];
        $page['grantable'] = (bool)$page['grantable'];
        $page['owner_only'] = (bool)$page['owner_only'];
        if ($page['owner_only']) {
            $page['grantable'] = false;
            $page['landing_eligible'] = false;
        }
        if (!$page['nav_visible']) {
            $page['landing_eligible'] = false;
        }
        if (!in_array((string)$page['badge_display'], ['self', 'parent', 'self_parent'], true)) {
            throw new InvalidArgumentException('Unknown badge display mode for ' . $key);
        }
        $this->pages[$key] = $page;
        uasort($this->pages, static fn (array $a, array $b): int => ((int)$a['order'] <=> (int)$b['order']) ?: strcmp((string)$a['key'], (string)$b['key']));
    }

    /** @param list<array<string,mixed>> $pages @return array<string,array<string,list<array<string,mixed>>>> */
    private function treeFrom(array $pages): array
    {
        $tree = [];
        foreach ($pages as $page) {
            $tree[(string)$page['section']][(string)$page['group']][] = $page;
        }
        return $tree;
    }

    /** @return array<string,mixed> */
    private static function definition(
        string $key,
        string $label,
        string $section,
        string $group,
        int $order,
        string $path,
        bool $navVisible = true,
        bool $landingEligible = true,
        bool $grantable = true,
        ?string $badgeProvider = null,
        string $badgeDisplay = 'self',
        bool $ownerOnly = false,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'section' => $section,
            'group' => $group,
            'order' => $order,
            'path' => $path,
            'nav_visible' => $navVisible,
            'landing_eligible' => $landingEligible,
            'grantable' => $grantable,
            'badge_provider' => $badgeProvider,
            'badge_display' => $badgeDisplay,
            'owner_only' => $ownerOnly,
        ];
    }

    private function endpointKey(string $method, string $path): string
    {
        return strtoupper(trim($method)) . ' ' . (rtrim('/' . ltrim($path, '/'), '/') ?: '/');
    }
}
