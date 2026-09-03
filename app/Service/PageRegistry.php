<?php

namespace App\Service;

/**
 * Canonical source of truth for company ERP pages.
 *
 * A page key is stable business identity. Titles, URLs, groups and ordering may
 * change without changing the key. Protected endpoint mapping lives here too,
 * so menu/matrix/server authorization cannot drift into separate lists.
 */
final class PageRegistry
{
    /** @return array<string,array> */
    public static function pages(): array
    {
        return [
            'company.dashboard' => self::page('Обзор', 'operations', null, 10, null, '/company/dashboard', [
                ['GET', '/company/dashboard'],
            ]),

            'company.clients' => self::page('Клиенты', 'operations', null, 20, 'Клиенты', '/company/clients', [
                ['GET', '/company/clients'],
                ['GET', '/company/clients/create'], ['POST', '/company/clients/create'],
                ['GET', '/company/clients/{id}'],
                ['GET', '/company/clients/{id}/edit'], ['POST', '/company/clients/{id}/edit'],
                ['POST', '/company/clients/{id}/archive'],
                ['GET', '/company/clients/{id}/modal-view'],
                ['GET', '/company/clients/{id}/modal-edit'], ['POST', '/company/clients/{id}/modal-edit'],
                ['POST', '/company/clients/{id}/modal-archive'],
                // Shared server endpoint: contractor and client forms may both use INN lookup.
                ['GET', '/company/requisites/lookup-by-inn'], ['POST', '/company/requisites/lookup-by-inn'],
            ], 'client'),

            'company.trips.linear' => self::page('Линейные рейсы', 'operations', 'trips', 30, 'Линейные', '/company/trips/linear', [
                ['GET', '/company/trips/linear'],
                ['GET', '/company/trips/linear/service-lines'],
                ['POST', '/company/trips/linear/create'],
                ['GET', '/company/trips/linear/{id}/modal-view'],
                ['GET', '/company/trips/linear/{id}/modal-edit'], ['POST', '/company/trips/linear/{id}/modal-edit'],
                ['POST', '/company/trips/linear/{id}/modal-delete'],
                ['GET', '/company/trips/linear/cargo-types'],
            ], 'linear_trip'),

            'company.trips.waste' => self::page('Рейсы с отходами', 'operations', 'trips', 40, 'Отходы', '/company/trips/departures', [
                ['GET', '/company/trips/departures'],
                ['POST', '/company/trips/departures/preview'],
                ['POST', '/company/trips/departures/create'],
                ['GET', '/company/trips/departures/import'],
                ['POST', '/company/trips/departures/import/{legacyId}'],
                ['GET', '/company/trips/departures/{id}/modal-view'],
                ['GET', '/company/trips/departures/{id}/modal-edit'], ['POST', '/company/trips/departures/{id}/modal-edit'],
                ['POST', '/company/trips/departures/{id}/cancel'],
            ], 'waste_trip'),

            'company.feo.requests' => self::page('Заявки ФЭО — Все заявки', 'operations', 'feo', 50, 'Все заявки', '/company/feo/requests', [
                ['GET', '/company/feo/requests'],
                ['GET', '/company/feo/requests/data'],
                ['GET', '/company/feo/requests/{id}/json'],
                ['POST', '/company/feo/requests/{id}/update'],
                ['POST', '/company/feo/requests/import/analyze'],
                ['POST', '/company/feo/requests/import/commit'],
                ['POST', '/company/feo/requests/import/rollback'],
                ['POST', '/company/feo/requests/import/restore'],
                ['POST', '/company/feo/requests/import/delete'],
            ]),

            'company.feo.map_placement' => self::page('Заявки ФЭО — Размещение на карте', 'operations', 'feo', 60, 'Размещение на карте', '/company/feo/requests/map-placement', [
                ['GET', '/company/feo/requests/map-placement'],
                ['POST', '/company/feo/requests/map-placement/validate-manual'],
                ['GET', '/company/feo/requests/map-placement/search-blocks'],
                ['POST', '/company/feo/requests/map-placement/analyze'],
                ['POST', '/company/feo/requests/map-placement/commit'],
                ['GET', '/company/feo/requests/map-placement/current'],
                ['GET', '/company/feo/requests/map-placement/blocks/{id}/json'],
                ['GET', '/company/feo/requests/map-placement/blocks/{id}/download'],
                ['POST', '/company/feo/requests/map-placement/blocks/{id}/preview-status'],
                ['POST', '/company/feo/requests/map-placement/status/commit'],
                ['POST', '/company/feo/requests/map-placement/blocks/{id}/preview-delete'],
                ['POST', '/company/feo/requests/map-placement/delete/commit'],
            ]),

            'company.feo.request_costs' => self::page('Заявки ФЭО — Стоимость заявок', 'operations', 'feo', 70, 'Стоимость заявок', '/company/feo/requests/costs', [
                ['GET', '/company/feo/requests/costs'],
                ['POST', '/company/feo/requests/costs/validate'],
                ['POST', '/company/feo/requests/costs/analyze'],
                ['POST', '/company/feo/requests/costs/commit'],
                ['GET', '/company/feo/requests/costs/search'],
                ['GET', '/company/feo/requests/costs/blocks/{id}/json'],
                ['POST', '/company/feo/requests/costs/blocks/{id}/edit/validate'],
                ['POST', '/company/feo/requests/costs/blocks/{id}/edit/analyze'],
                ['POST', '/company/feo/requests/costs/edit/commit'],
            ]),

            'company.route_executors' => self::page('Исполнители рейса', 'operations', null, 80, 'Исполнители рейса', '/company/route-executors', [
                ['GET', '/company/route-executors'],
                ['GET', '/company/route-executors/create'], ['POST', '/company/route-executors/create'],
                ['GET', '/company/route-executors/{id}'],
                ['GET', '/company/route-executors/{id}/edit'], ['POST', '/company/route-executors/{id}/edit'],
                ['POST', '/company/route-executors/{id}/archive'],
            ]),

            'company.document_generator.create' => self::page('Генератор договоров — Создать документ', 'operations', 'document_generator', 90, 'Создать документ', '/company/document-generator', [
                ['GET', '/company/document-generator'],
                ['POST', '/company/document-generator/preview'],
                ['POST', '/company/document-generator/generate'],
            ]),
            'company.document_generator.history' => self::page('Генератор договоров — История', 'operations', 'document_generator', 100, 'История документов', '/company/document-generator/history', [
                ['GET', '/company/document-generator/history'],
                ['GET', '/company/document-generator/generated/{id}/download'],
            ]),
            'company.document_generator.templates' => self::page('Генератор договоров — Шаблоны', 'operations', 'document_generator', 110, 'Шаблоны договоров', '/company/document-generator/templates', [
                ['GET', '/company/document-generator/templates'],
                ['POST', '/company/document-generator/templates/upload'],
                ['POST', '/company/document-generator/templates/{id}/status'],
                ['GET', '/company/document-generator/templates/{templateId}/versions/{versionId}/download'],
            ]),

            'company.contractors' => self::page('Подрядчики', 'operations', 'directories', 120, 'Подрядчики', '/company/contractors', [
                ['GET', '/company/contractors'],
                ['GET', '/company/contractors/create'], ['POST', '/company/contractors/create'],
                ['GET', '/company/contractors/create-full'], ['POST', '/company/contractors/create-full'],
                ['GET', '/company/contractors/{id}/add-crew'], ['POST', '/company/contractors/{id}/add-crew'],
                ['GET', '/company/contractors/{id}'],
                ['GET', '/company/contractors/{id}/edit'], ['POST', '/company/contractors/{id}/edit'],
                ['POST', '/company/contractors/{id}/archive'],
                ['GET', '/company/contractors/{id}/modal-view'],
                ['GET', '/company/contractors/{id}/modal-edit'], ['POST', '/company/contractors/{id}/modal-edit'],
                ['POST', '/company/contractors/{id}/modal-archive'],
                ['POST', '/company/contractors/{contractor_id}/contacts/create'],
                ['POST', '/company/contractors/{contractor_id}/contacts/{contact_id}/edit'],
                ['POST', '/company/contractors/{contractor_id}/contacts/{contact_id}/delete'],
                ['POST', '/company/contractors/{contractor_id}/contacts/{contact_id}/set-primary'],
                ['POST', '/company/contractors/{contractor_id}/contacts/{contact_id}/set-document-email'],
                ['POST', '/company/contractors/{contractor_id}/tax-history/create'],
                ['GET', '/company/requisites/lookup-by-inn'], ['POST', '/company/requisites/lookup-by-inn'],
            ], 'contractor'),

            'company.drivers' => self::page('Водители', 'operations', 'directories', 130, 'Водители', '/company/drivers', [
                ['GET', '/company/drivers'],
                ['GET', '/company/drivers/create'], ['POST', '/company/drivers/create'],
                ['POST', '/company/drivers/modal-create'],
                ['GET', '/company/drivers/{id}'],
                ['GET', '/company/drivers/{id}/edit'], ['POST', '/company/drivers/{id}/edit'],
                ['POST', '/company/drivers/{id}/archive'],
                ['GET', '/company/drivers/{id}/modal-view'],
                ['GET', '/company/drivers/{id}/modal-edit'], ['POST', '/company/drivers/{id}/modal-edit'],
                ['POST', '/company/drivers/{id}/modal-delete'],
                ['POST', '/company/drivers/{driver_id}/phones/create'],
                ['POST', '/company/drivers/{driver_id}/phones/{phone_id}/edit'],
                ['POST', '/company/drivers/{driver_id}/phones/{phone_id}/delete'],
                ['POST', '/company/drivers/{driver_id}/phones/{phone_id}/set-main'],
                ['POST', '/company/drivers/{driver_id}/documents/upload'],
                ['POST', '/company/drivers/{driver_id}/documents/{doc_id}/delete'],
            ], 'driver'),

            'company.vehicles' => self::page('Транспортные единицы', 'operations', 'directories', 140, 'ТС', '/company/vehicles', [
                ['GET', '/company/vehicles'],
                ['GET', '/company/vehicles/create'], ['POST', '/company/vehicles/create'],
                ['GET', '/company/vehicles/{id}'],
                ['GET', '/company/vehicles/{id}/edit'], ['POST', '/company/vehicles/{id}/edit'],
                ['POST', '/company/vehicles/{id}/archive'],
            ], 'vehicle_unit'),

            'company.vehicle_sets' => self::page('Комплекты ТС', 'operations', 'directories', 150, 'Комплекты ТС', '/company/vehicle-sets', [
                ['GET', '/company/vehicle-sets'],
                ['GET', '/company/vehicle-sets/create'], ['POST', '/company/vehicle-sets/create'],
                ['GET', '/company/vehicle-sets/{id}'],
                ['GET', '/company/vehicle-sets/{id}/modal-view'],
                ['GET', '/company/vehicle-sets/{id}/modal-edit'], ['POST', '/company/vehicle-sets/{id}/modal-edit'],
                ['POST', '/company/vehicle-sets/{id}/modal-archive'],
                ['POST', '/company/vehicle-sets/{id}/archive'],
            ]),

            'company.finance.dashboard' => self::page('Финансы — Дашборд', 'finance', null, 200, 'Дашборд', '/company/finance/dashboard', [
                ['GET', '/company/finance/dashboard'],
            ]),
            'company.finance.bank_accounts' => self::page('Финансы — Выписки со счёта', 'finance', null, 210, 'Выписки со счёта', '/company/finance/bank-accounts', [
                ['GET', '/company/finance/bank-accounts'],
                ['POST', '/company/finance/bank-accounts/import'],
                ['POST', '/company/finance/bank-accounts/refresh-from-mail'],
                ['POST', '/company/finance/bank-accounts/apply-rules'],
                ['POST', '/company/finance/bank-accounts/settings'],
                ['POST', '/company/finance/bank-accounts/import/delete'],
                ['GET', '/company/finance/bank-accounts/reconciliation/json'],
                ['GET', '/company/finance/bank-transactions/{id}/settlement'], ['POST', '/company/finance/bank-transactions/{id}/settlement'],
                ['GET', '/company/finance/bank-transactions/{id}/classify'], ['POST', '/company/finance/bank-transactions/{id}/classify'],
                ['POST', '/company/finance/bank-transactions/{id}/classification/delete'],
            ]),
            'company.finance.closing_documents' => self::page('Финансы — Закрывающие документы', 'finance', null, 220, 'Закрывающие документы', '/company/finance/closing-documents', [
                ['GET', '/company/finance/closing-documents'],
                ['GET', '/company/finance/closing-documents/{id}/modal-view'],
                ['GET', '/company/finance/closing-documents/exceptions'],
                ['GET', '/company/finance/closing-documents/exceptions/create'],
                ['GET', '/company/finance/closing-documents/exceptions/edit'],
                ['POST', '/company/finance/closing-documents/exceptions/save'],
                ['POST', '/company/finance/closing-documents/exceptions/remove'],
                ['POST', '/company/finance/closing-documents/requirement-overrides'],
                ['POST', '/company/finance/closing-documents/link-document'],
                ['POST', '/company/finance/closing-documents/upload-document'],
                ['POST', '/company/finance/closing-documents/document-edo'],
                ['POST', '/company/finance/closing-documents/archive'],
                ['POST', '/company/finance/closing-documents/unlink-document'],
            ]),
            'company.finance.employee_payments' => self::page('Финансы — Операции сотрудников', 'finance', null, 230, 'Касса / сотрудники', '/company/finance/employee-payments', [
                ['GET', '/company/finance/employee-payments'],
                ['GET', '/company/finance/employee-payments/create'], ['POST', '/company/finance/employee-payments/create'],
                ['POST', '/company/finance/employee-payments/client-receipt/create'],
                ['POST', '/company/finance/employee-payments/client-receipt/update'],
                ['POST', '/company/finance/employee-payments/client-receipt/cancel'],
                ['POST', '/company/finance/employee-payments/transfer'],
                ['POST', '/company/finance/employee-payments/transfer/update'],
                ['POST', '/company/finance/employee-payments/transfer/delete'],
                ['GET', '/company/finance/employee-payments/employee/{type}/{id}'],
                ['POST', '/company/finance/employee-payments/movements/{id}/reassign'],
                ['POST', '/company/finance/employee-payments/bank-link'],
                ['POST', '/company/finance/employee-payments/bank-unlink'],
                ['POST', '/company/finance/employee-payments/invoice-payment/create'],
                ['POST', '/company/finance/employee-payments/invoice-payment/update'],
                ['POST', '/company/finance/employee-payments/invoice-payment/cancel'],
                ['GET', '/company/finance/employee-payments/personal-expenses'],
                ['GET', '/company/finance/employee-payments/personal-expense/create'],
                ['POST', '/company/finance/employee-payments/personal-expense/create'],
                ['GET', '/company/finance/employee-payments/personal-expense/{id}/edit'],
                ['POST', '/company/finance/employee-payments/personal-expense/{id}/update'],
                ['POST', '/company/finance/employee-payments/personal-expense/{id}/cancel'],
                // Backward-compatible retired cash URLs resolve to this canonical page.
                ['GET', '/company/finance/cash'],
                ['GET', '/company/finance/cash/account-create'], ['POST', '/company/finance/cash/account-create'],
                ['GET', '/company/finance/cash/operation-create'], ['POST', '/company/finance/cash/operation-create'],
                ['GET', '/company/finance/cash/transfer-create'], ['POST', '/company/finance/cash/transfer-create'],
                ['POST', '/company/finance/cash/dispatch-employee'],
            ]),
            'company.finance.invoices' => self::page('Финансы — Счета', 'finance', null, 240, 'Счета', '/company/finance/invoices', [
                ['GET', '/company/finance/invoices'],
                ['GET', '/company/finance/receivables'],
                ['GET', '/company/finance/payables'],
                ['POST', '/company/finance/invoices/create'],
                ['GET', '/company/finance/invoices/{id}/modal-view'],
                ['GET', '/company/finance/invoices/{id}/modal-edit'], ['POST', '/company/finance/invoices/{id}/modal-edit'],
                ['POST', '/company/finance/invoices/{id}/modal-delete'],
                ['POST', '/company/finance/invoices/{id}/pay-cash'],
                ['POST', '/company/finance/invoices/{id}/settlements/{operationId}/date'],
                ['GET', '/company/finance/invoices/obligations'],
                ['GET', '/company/finance/invoices/{id}/history'],
                ['GET', '/company/finance/invoices/route-payments'],
            ]),
            'company.finance.operations' => self::page('Финансы — Операции', 'finance', null, 250, 'Операции', '/company/finance/operations', [
                ['GET', '/company/finance/operations'],
                ['GET', '/company/finance/operations/{id}/modal-view'],
                ['GET', '/company/finance/operations/{id}/allocate'], ['POST', '/company/finance/operations/{id}/allocate'],
                ['POST', '/company/finance/operations/allocations/{id}/cancel'],
                ['POST', '/company/finance/operations/{id}/cancel'],
                ['GET', '/company/finance/operations/{id}/history'],
                ['GET', '/company/finance/operations/route-payments'],
            ]),
            'company.finance.dds_categories' => self::page('Финансы — Финансовая структура', 'finance', null, 260, 'Финансовая структура', '/company/finance/settings/dds-categories', [
                ['GET', '/company/finance/settings/dds-categories'],
                ['GET', '/company/finance/settings/dds-categories/create'], ['POST', '/company/finance/settings/dds-categories/create'],
                ['GET', '/company/finance/settings/dds-categories/edit'], ['POST', '/company/finance/settings/dds-categories/edit'],
                ['POST', '/company/finance/settings/dds-categories/active-toggle'],
                ['POST', '/company/finance/settings/dds-categories/delete'],
                ['POST', '/company/finance/settings/dds-categories/cfu/save'],
                ['POST', '/company/finance/settings/dds-categories/cfu/toggle'],
                ['POST', '/company/finance/settings/dds-categories/cfu/delete'],
                ['POST', '/company/finance/settings/dds-categories/links/save'],
            ]),
            'company.finance.matching_rules' => self::page('Финансы — Правила разнесения', 'finance', null, 270, 'Правила разнесения', '/company/finance/settings/matching-rules', [
                ['GET', '/company/finance/settings/matching-rules'],
                ['GET', '/company/finance/settings/matching-rules/create'], ['POST', '/company/finance/settings/matching-rules/create'],
                ['GET', '/company/finance/settings/matching-rules/edit'], ['POST', '/company/finance/settings/matching-rules/edit'],
                ['POST', '/company/finance/settings/matching-rules/toggle'],
                ['POST', '/company/finance/settings/matching-rules/reorder'],
                ['POST', '/company/finance/settings/matching-rules/remove'],
                ['GET', '/company/finance/settings/matching-rules/preview'],
                ['GET', '/company/finance/settings/matching-rules/test'],
                ['POST', '/company/finance/settings/matching-rules/cfu/save'],
                ['POST', '/company/finance/settings/matching-rules/cfu/toggle'],
            ]),

            // Remaining finance report/settings pages are canonical even if their
            // route families are small; endpoint patterns are intentionally exact.
            'company.finance.payment_calendar' => self::page('Финансы — Платёжный календарь', 'finance', null, 280, 'Платёжный календарь', '/company/finance/payment-calendar', [
                ['GET', '/company/finance/payment-calendar'],
            ]),
            'company.finance.cash_flow' => self::page('Финансы — БДДС', 'finance', 'finance_reports', 290, 'БДДС', '/company/finance/reports/cash-flow', [
                ['GET', '/company/finance/reports/cash-flow'],
            ]),
            'company.finance.management_balance' => self::page('Финансы — Управленческий баланс', 'finance', 'finance_reports', 300, 'Упр. баланс', '/company/finance/reports/management-balance', [
                ['GET', '/company/finance/reports/management-balance'],
            ]),
            'company.finance.payment_plan_fact' => self::page('Финансы — План/факт платежей', 'finance', 'finance_reports', 310, 'План/факт', '/company/finance/reports/payment-plan-fact', [
                ['GET', '/company/finance/reports/payment-plan-fact'],
            ]),

            'company.bank_statement_settings' => self::page('Настройки выписок', 'system', null, 400, 'Настройки выписок', '/company/bank-statement-settings', [
                ['GET', '/company/bank-statement-settings'],
            ]),

            // One canonical administration page. Old user/reassignment URLs are
            // compatibility endpoints of this page, not a second admin ACL.
            'company.roles_access' => self::page('Роли и доступы', 'system', null, 410, 'Роли и доступы', '/company/roles-access', [
                ['GET', '/company/roles-access'],
                ['GET', '/company/roles-access/roles/{id}'],
                ['POST', '/company/roles-access/roles/create'],
                ['POST', '/company/roles-access/roles/{id}/save'],
                ['POST', '/company/roles-access/roles/{id}/copy'],
                ['POST', '/company/roles-access/roles/{id}/delete'],
                ['GET', '/company/roles-access/users/{id}'],
                ['POST', '/company/roles-access/users/add'],
                ['POST', '/company/roles-access/users/{id}/save'],
                ['POST', '/company/roles-access/assignments/toggle'],
                ['GET', '/company/logists'],
                ['GET', '/company/logists/create'], ['POST', '/company/logists/create'],
                ['GET', '/company/logists/modal-create'], ['POST', '/company/logists/modal-create'],
                ['GET', '/company/logists/{id}'],
                ['GET', '/company/logists/{id}/edit'], ['POST', '/company/logists/{id}/edit'],
                ['POST', '/company/logists/{id}/reset-password'],
                ['GET', '/company/logists/{id}/modal-view'],
                ['GET', '/company/logists/{id}/modal-edit'], ['POST', '/company/logists/{id}/modal-edit'],
                ['POST', '/company/logists/{id}/archive'],
                ['GET', '/company/responsible-assignments'],
                ['POST', '/company/responsible-assignments/reassign'],
            ]),

            // Existing document repository is a protected page even though it is
            // not a first-level sidebar item today.
            'company.documents' => self::page('Документы', 'system', null, 420, null, '/company/documents', [
                ['GET', '/company/documents'],
                ['GET', '/company/documents/upload'], ['POST', '/company/documents/upload'],
                ['GET', '/company/documents/download'], ['GET', '/company/documents/view'],
                ['POST', '/company/documents/delete'], ['POST', '/company/documents/replace'],
            ]),
            'company.document_types' => self::page('Типы документов', 'system', null, 430, null, '/company/document-types', [
                ['GET', '/company/document-types'],
                ['GET', '/company/document-types/create'], ['POST', '/company/document-types/create'],
                ['GET', '/company/document-types/{id}/edit'], ['POST', '/company/document-types/{id}/edit'],
                ['POST', '/company/document-types/{id}/delete'],
            ]),
        ];
    }

    public static function groups(): array
    {
        return [
            'trips' => ['title' => 'Рейсы', 'order' => 30],
            'feo' => ['title' => 'Заявки ФЭО', 'order' => 50],
            'document_generator' => ['title' => 'Генератор договоров', 'order' => 90],
            'directories' => ['title' => 'Справочники', 'order' => 120],
            'finance_reports' => ['title' => 'Отчёты', 'order' => 290],
        ];
    }

    public static function sections(): array
    {
        return [
            'operations' => ['title' => 'ОПЕРАЦИИ', 'order' => 10],
            'finance' => ['title' => 'ФИНАНСЫ', 'order' => 20],
            'system' => ['title' => 'СИСТЕМА', 'order' => 30],
        ];
    }

    public static function get(string $pageKey): ?array
    {
        $pages = self::pages();
        return $pages[$pageKey] ?? null;
    }

    /** Matrix consumes this directly. Hidden-from-menu pages remain configurable. */
    public static function matrixPages(): array
    {
        $rows = [];
        foreach (self::pages() as $key => $page) {
            if (!($page['matrix'] ?? true)) {
                continue;
            }
            $rows[$key] = $page;
        }
        uasort($rows, static fn(array $a, array $b): int => [$a['section_order'], $a['order'], $a['title']] <=> [$b['section_order'], $b['order'], $b['title']]);
        return $rows;
    }

    /**
     * Resolve an exact current HTTP operation to its server-owned page-key OR-set.
     * Query-string/client `page_key` values are ignored by construction.
     */
    public static function pageKeysForRequest(string $method, string $path): array
    {
        $method = strtoupper(trim($method));
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        $matches = [];

        foreach (self::pages() as $pageKey => $page) {
            foreach ($page['endpoints'] as [$endpointMethod, $template]) {
                if ($endpointMethod !== $method) {
                    continue;
                }
                if (self::endpointMatches($template, $path)) {
                    $matches[$pageKey] = true;
                }
            }
        }

        return array_keys($matches);
    }

    /**
     * Build visible navigation from the same registry. $canAccess receives one
     * stable page key and must call EffectiveAccessResolver in final integration.
     * Empty sections/groups disappear automatically.
     */
    public static function navigationFor(callable $canAccess): array
    {
        $sections = [];
        foreach (self::sections() as $sectionKey => $sectionMeta) {
            $sections[$sectionKey] = $sectionMeta + ['key' => $sectionKey, 'items' => [], 'groups' => []];
        }

        $groups = self::groups();
        foreach (self::pages() as $pageKey => $page) {
            if ($page['menu_label'] === null || !$canAccess($pageKey)) {
                continue;
            }
            $sectionKey = $page['section'];
            if (!isset($sections[$sectionKey])) {
                continue;
            }
            $item = [
                'page_key' => $pageKey,
                'label' => $page['menu_label'],
                'path' => $page['menu_path'],
                'order' => $page['order'],
            ];

            $groupKey = $page['group'];
            if ($groupKey === null) {
                $sections[$sectionKey]['items'][] = $item;
                continue;
            }

            if (!isset($sections[$sectionKey]['groups'][$groupKey])) {
                $meta = $groups[$groupKey] ?? ['title' => $groupKey, 'order' => $page['order']];
                $sections[$sectionKey]['groups'][$groupKey] = $meta + ['key' => $groupKey, 'items' => []];
            }
            $sections[$sectionKey]['groups'][$groupKey]['items'][] = $item;
        }

        foreach ($sections as $sectionKey => &$section) {
            foreach ($section['groups'] as $groupKey => &$group) {
                usort($group['items'], static fn(array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
                if ($group['items'] === []) {
                    unset($section['groups'][$groupKey]);
                }
            }
            unset($group);
            uasort($section['groups'], static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
            usort($section['items'], static fn(array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
            if ($section['items'] === [] && $section['groups'] === []) {
                unset($sections[$sectionKey]);
            }
        }
        unset($section);
        uasort($sections, static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);

        return array_values($sections);
    }

    public static function dataEntityForPage(string $pageKey): ?string
    {
        $page = self::get($pageKey);
        return $page['data_entity'] ?? null;
    }

    private static function page(
        string $title,
        string $section,
        ?string $group,
        int $order,
        ?string $menuLabel,
        string $menuPath,
        array $endpoints,
        ?string $dataEntity = null
    ): array {
        $sections = self::sections();
        return [
            'title' => $title,
            'section' => $section,
            'section_title' => $sections[$section]['title'] ?? $section,
            'section_order' => (int) ($sections[$section]['order'] ?? 9999),
            'group' => $group,
            'order' => $order,
            'menu_label' => $menuLabel,
            'menu_path' => $menuPath,
            'endpoints' => $endpoints,
            'data_entity' => $dataEntity,
            'matrix' => true,
        ];
    }

    private static function endpointMatches(string $template, string $path): bool
    {
        $quoted = preg_quote($template, '~');
        $pattern = preg_replace('~\\\\\{[A-Za-z_][A-Za-z0-9_]*\\\\\}~', '[^/]+', $quoted);
        return is_string($pattern) && preg_match('~^' . $pattern . '$~D', $path) === 1;
    }
}
