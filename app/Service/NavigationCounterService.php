<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use Throwable;

final class NavigationCounterService
{
    /** @var array<string,array<string,int>> */
    private static array $requestCache = [];
    private static bool $tripBadgeRendererRegistered = false;

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
            self::registerTripBadgeRenderer(self::$requestCache[$cacheKey]);
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
            // Deliberately call the report source itself instead of duplicating a
            // SQL predicate here. This is the architectural contract:
            // sidebar count === report rows under "Только проблемные".
            $counters['closing_documents_problem'] = max(0, ClosingDocumentService::problemCount($tenant));
        } catch (Throwable) {
            // During a staged rollout migration 077 may not yet exist. The new
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
        self::registerTripBadgeRenderer($counters);
        return $counters;
    }

    /**
     * The current main layout already calls this service on every company-owner
     * page, but it only has hard-coded markup for the older finance counters.
     * Register one output-buffer pass so Trips child badges and their parent sum
     * are rendered globally without page-local JavaScript.
     *
     * @param array<string,int> $counters
     */
    private static function registerTripBadgeRenderer(array $counters): void
    {
        if (self::$tripBadgeRendererRegistered) return;
        self::$tripBadgeRendererRegistered = true;

        $linear = max(0, (int)($counters['linear_trips_problem'] ?? 0));
        $departures = max(0, (int)($counters['departures_problem'] ?? 0));
        $total = $linear + $departures;

        ob_start(static function (string $html) use ($linear, $departures, $total): string {
            $badge = static function (int $count, string $title): string {
                if ($count <= 0) return '';
                $label = $count > 999 ? '999+' : (string)$count;
                return '<span class="nav-count is-alert" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $label . '</span>';
            };

            if ($linear > 0) {
                $html = preg_replace(
                    '~(<a class="nav-sub-item[^"]*" href="[^"]*/company/trips/linear">\s*<span>Линейные</span>)(?!\s*<span class="nav-count)~u',
                    '$1' . $badge($linear, 'Требуется актуализировать дату выгрузки'),
                    $html
                ) ?? $html;
            }

            if ($departures > 0) {
                $html = preg_replace(
                    '~(<a class="nav-sub-item[^"]*" href="[^"]*/company/trips/departures">\s*<span>Отходы</span>)(?!\s*<span class="nav-count)~u',
                    '$1' . $badge($departures, 'Ошибки в рейсах отходов'),
                    $html
                ) ?? $html;
            }

            if ($total > 0) {
                $html = preg_replace(
                    '~(<span class="nav-label">Рейсы</span>)(?!\s*<span class="nav-count)~u',
                    '$1' . $badge($total, 'Всего ошибок в рейсах'),
                    $html
                ) ?? $html;
            }

            return $html;
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
