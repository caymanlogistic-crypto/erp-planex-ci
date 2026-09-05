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
    /** @var array<string, array{key:string,label:string,section:string,group:string,order:int,path:string}> */
    private array $pages = [];

    /** @var array<string, list<string>> */
    private array $endpoints = [];

    /**
     * @param list<array{key:string,label:string,section:string,group:string,order:int,path:string}>|null $pages
     */
    public function __construct(?array $pages = null)
    {
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
            self::definition('company.trips.linear', 'Линейные', 'Операции', 'Рейсы', 30, '/company/trips/linear'),
            self::definition('company.trips.waste', 'Отходы', 'Операции', 'Рейсы', 40, '/company/trips/departures'),
            self::definition('company.feo.requests', 'Все заявки', 'Операции', 'Заявки ФЭО', 50, '/company/feo/requests'),
            self::definition('company.feo.costs', 'Стоимость заявок', 'Операции', 'Заявки ФЭО', 60, '/company/feo/requests/costs'),
            self::definition('company.feo.map', 'Карта заявок', 'Операции', 'Заявки ФЭО', 70, '/company/feo/requests/map'),
            self::definition('company.feo.map_placement', 'Размещение на карте', 'Операции', 'Заявки ФЭО', 80, '/company/feo/requests/map-placement'),
            self::definition('company.feo.utilizers', 'Утилизаторы', 'Операции', 'Заявки ФЭО', 90, '/company/feo/utilizers'),
            self::definition('company.route_executors', 'Исполнители рейса', 'Операции', '', 100, '/company/route-executors'),
            self::definition('company.documents.create', 'Создать документ', 'Операции', 'Генератор договоров', 110, '/company/document-generator'),
            self::definition('company.documents.history', 'История документов', 'Операции', 'Генератор договоров', 120, '/company/document-generator/history'),
            self::definition('company.documents.templates', 'Шаблоны договоров', 'Операции', 'Генератор договоров', 130, '/company/document-generator/templates'),
            self::definition('company.contractors', 'Подрядчики', 'Справочники', '', 200, '/company/contractors'),
            self::definition('company.drivers', 'Водители', 'Справочники', '', 210, '/company/drivers'),
            self::definition('company.vehicle_units', 'ТС', 'Справочники', '', 220, '/company/vehicles'),
            self::definition('company.vehicle_sets', 'Составы ТС', 'Справочники', '', 230, '/company/vehicle-sets'),
            self::definition('company.finance.dashboard', 'Дашборд', 'Финансы', '', 300, '/company/finance/dashboard'),
            self::definition('company.finance.bank_accounts', 'Выписки со счёта', 'Финансы', '', 310, '/company/finance/bank-accounts'),
            self::definition('company.finance.closing_documents', 'Закрывающие документы', 'Финансы', '', 320, '/company/finance/closing-documents'),
            self::definition('company.finance.cash', 'Касса', 'Финансы', '', 330, '/company/finance/cash'),
            self::definition('company.finance.invoices', 'Счета', 'Финансы', '', 340, '/company/finance/invoices'),
            self::definition('company.finance.operations', 'Операции', 'Финансы', '', 350, '/company/finance/operations'),
            self::definition('company.finance.dds_categories', 'Статьи ДДС', 'Финансы', 'Настройки', 360, '/company/finance/settings/dds-categories'),
            self::definition('company.finance.payment_calendar', 'Платёжный календарь', 'Финансы', 'Отчёты', 370, '/company/finance/payment-calendar'),
            self::definition('company.finance.cash_flow', 'БДДС', 'Финансы', 'Отчёты', 380, '/company/finance/reports/cash-flow'),
            self::definition('company.finance.management_balance', 'Управленческий баланс', 'Финансы', 'Отчёты', 390, '/company/finance/reports/management-balance'),
            self::definition('company.finance.payment_plan_fact', 'План-факт оплаты', 'Финансы', 'Отчёты', 400, '/company/finance/reports/payment-plan-fact'),
            self::definition('company.finance.matching_rules', 'Правила разнесения', 'Финансы', 'Настройки', 410, '/company/finance/settings/matching-rules'),
            self::definition('company.finance.bank_settings', 'Настройки выписок', 'Финансы', 'Настройки', 420, '/company/bank-statement-settings'),
            self::definition('company.finance.employee_payments', 'Расчёты с сотрудниками', 'Финансы', '', 430, '/company/finance/employee-payments'),
            self::definition('company.production_calendar', 'Производственный календарь', 'Настройки', '', 500, '/company/misc/production-calendar'),
            self::definition('company.roles_access', 'Роли и доступы', 'Настройки', '', 510, '/company/roles-access'),
        ];
    }

    /** @return list<string> */
    public function pageKeys(): array
    {
        return array_keys($this->pages);
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

    /** @return list<array{key:string,label:string,section:string,group:string,order:int,path:string}> */
    public function pages(): array
    {
        return array_values($this->pages);
    }

    /**
     * @return array<string, array<string, list<array{key:string,label:string,section:string,group:string,order:int,path:string}>>>
     */
    public function tree(): array
    {
        $tree = [];
        foreach ($this->pages as $page) {
            $tree[$page['section']][$page['group']][] = $page;
        }

        return $tree;
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
                throw new InvalidArgumentException('Unknown page key: ' . $key);
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

    /** @param array{key:string,label:string,section:string,group:string,order:int,path:string} $page */
    private function registerPage(array $page): void
    {
        $key = trim($page['key'] ?? '');
        if ($key === '' || isset($this->pages[$key])) {
            throw new InvalidArgumentException('Page key must be non-empty and unique.');
        }
        if (($page['label'] ?? '') === '' || ($page['section'] ?? '') === '' || ($page['path'] ?? '') === '') {
            throw new InvalidArgumentException('Page metadata is incomplete for ' . $key);
        }
        $page['key'] = $key;
        $this->pages[$key] = $page;
        uasort($this->pages, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    }

    /** @return array{key:string,label:string,section:string,group:string,order:int,path:string} */
    private static function definition(string $key, string $label, string $section, string $group, int $order, string $path): array
    {
        return compact('key', 'label', 'section', 'group', 'order', 'path');
    }

    private function endpointKey(string $method, string $path): string
    {
        return strtoupper(trim($method)) . ' ' . (rtrim('/' . ltrim($path, '/'), '/') ?: '/');
    }
}
