<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use Throwable;

final class NavigationCounterService
{
    /** @var array<string,array<string,int>> */
    private static array $requestCache = [];
    private static bool $tripBadgeScriptRegistered = false;

    /**
     * Return lightweight navigation counters for one tenant.
     *
     * Every counter is intentionally isolated. A missing/temporarily unavailable
     * schema object for one module must never suppress unrelated badges.
     *
     * @return array{bank_attention:int,cash_attention:int,closing_documents_problem:int,linear_trips_problem:int,departures_problem:int}
     */
    public static function forCompany(array $config, Database $centralDb, int $companyId, ?array $knownCompany = null): array
    {
        if ($companyId <= 0) return self::emptyCounters();

        $cacheKey = (string)$companyId;
        if (isset(self::$requestCache[$cacheKey])) {
            self::registerTripBadgeScript(self::$requestCache[$cacheKey]);
            return self::$requestCache[$cacheKey];
        }

        try {
            $company = $knownCompany;
            if (!is_array($company) || (int)($company['id'] ?? 0) !== $companyId || ($company['status'] ?? '') !== 'active') {
                $stmt = $centralDb->connection()->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$companyId]);
                $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$company) return self::$requestCache[$cacheKey] = self::emptyCounters();

            $tenant = (new Database(companyDatabaseConfig($config, $company)))->connection();
        } catch (Throwable) {
            return self::$requestCache[$cacheKey] = self::emptyCounters();
        }

        $counters = self::emptyCounters();

        try {
            $counters['bank_attention'] = max(0, (int)$tenant->query(
                "SELECT COUNT(*)
                   FROM bank_transactions
                  WHERE classification_status IN ('UNALLOCATED','NEEDS_REVIEW')
                    AND COALESCE(is_internal_transfer, 0) = 0"
            )->fetchColumn());
        } catch (Throwable) {
            $counters['bank_attention'] = 0;
        }

        try {
            $counters['cash_attention'] = max(
                0,
                (int)(FinanceCashResolutionService::unresolvedSummary($tenant)['count'] ?? 0)
            );
        } catch (Throwable) {
            $counters['cash_attention'] = 0;
        }

        try {
            // Keep the sidebar badge on the same compliance-aware source as the
            // report itself, including EDO, manual collected and per-INN exclusions.
            $counters['closing_documents_problem'] = max(0, ClosingDocumentComplianceService::problemCount($tenant));
        } catch (Throwable) {
            // During a staged rollout migration 121 may not yet exist. The new
            // counter alone degrades to zero; bank/cash navigation remains intact.
            $counters['closing_documents_problem'] = 0;
        }

        try {
            // A linear trip needs attention when the actual unloading date is not
            // recorded and its planned unloading date has already arrived.
            $counters['linear_trips_problem'] = max(0, (int)$tenant->query(
                "SELECT COUNT(*)
                   FROM linear_routes
                  WHERE deleted_at IS NULL
                    AND planned_unloading_date IS NOT NULL
                    AND planned_unloading_date <> ''
                    AND (actual_unloading_date IS NULL OR actual_unloading_date = '')
                    AND DATE(planned_unloading_date) <= CURRENT_DATE()"
            )->fetchColumn());
        } catch (Throwable) {
            $counters['linear_trips_problem'] = 0;
        }

        // Reserved for the sibling Trips submenu. Keeping it explicit makes the
        // parent counter a true sum of child counters instead of a hard-coded
        // alias to the Linear Trips value.
        $counters['departures_problem'] = 0;

        self::$requestCache[$cacheKey] = $counters;
        self::registerTripBadgeScript($counters);
        return $counters;
    }

    /**
     * Render trip counters after the layout has finished instead of opening a
     * nested output buffer. LinearTripController itself buffers the rendered page,
     * so an ob_start callback here could be consumed by the controller and make
     * the sidebar badges disappear. The small DOM upsert below is global for all
     * company-owner pages because this service is already called by the main layout.
     *
     * @param array<string,int> $counters
     */
    private static function registerTripBadgeScript(array $counters): void
    {
        if (self::$tripBadgeScriptRegistered) return;
        self::$tripBadgeScriptRegistered = true;

        $linear = max(0, (int)($counters['linear_trips_problem'] ?? 0));
        $departures = max(0, (int)($counters['departures_problem'] ?? 0));
        $total = $linear + $departures;
        if ($total <= 0) return;

        register_shutdown_function(static function () use ($linear, $departures, $total): void {
            $payload = json_encode(
                ['linear' => $linear, 'departures' => $departures, 'total' => $total],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (!is_string($payload)) return;

            echo '<script>(function(){\'use strict\';var counts=' . $payload . ';'
                . 'function label(n){return n>999?\'999+\':String(n)}'
                . 'function upsert(host,count,title,before){if(!host)return;var badge=host.querySelector(\':scope > [data-trip-nav-count]\');if(count<=0){if(badge)badge.remove();return;}if(!badge){badge=document.createElement(\'span\');badge.className=\'nav-count is-alert\';badge.setAttribute(\'data-trip-nav-count\',\'\');if(before)host.insertBefore(badge,before);else host.appendChild(badge);}badge.textContent=label(count);badge.title=title;}'
                . 'function run(){var linear=document.querySelector(\'a.nav-sub-item[href$="/company/trips/linear"]\');var departures=document.querySelector(\'a.nav-sub-item[href$="/company/trips/departures"]\');upsert(linear,counts.linear,\'Требуется актуализировать дату выгрузки\',null);upsert(departures,counts.departures,\'Ошибки в рейсах отходов\',null);var parent=linear&&linear.closest(\'.nav-sub\')?linear.closest(\'.nav-sub\').previousElementSibling:null;var chevron=parent?parent.querySelector(\'.nav-chevron\'):null;upsert(parent,counts.total,\'Всего ошибок в рейсах\',chevron);}'
                . 'if(document.readyState===\'loading\')document.addEventListener(\'DOMContentLoaded\',run,{once:true});else run();})();</script>';
        });
    }

    /** @return array{bank_attention:int,cash_attention:int,closing_documents_problem:int,linear_trips_problem:int,departures_problem:int} */
    private static function emptyCounters(): array
    {
        return [
            'bank_attention' => 0,
            'cash_attention' => 0,
            'closing_documents_problem' => 0,
            'linear_trips_problem' => 0,
            'departures_problem' => 0,
        ];
    }
}
