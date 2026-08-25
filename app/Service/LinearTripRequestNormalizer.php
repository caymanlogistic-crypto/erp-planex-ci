<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use Throwable;

final class LinearTripRequestNormalizer
{
    /**
     * Repair stale/blank date mode controls from the canonical submitted dates.
     * Explicit valid modes are preserved. This keeps create/edit compatible with
     * cached clients and prevents a filled end date from being discarded.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function normalizeDateKinds(array $post): array
    {
        $startKind = trim((string) ($post['start_date_kind'] ?? ''));
        if (!in_array($startKind, ['plan', 'fact'], true)) {
            $post['start_date_kind'] = trim((string) ($post['actual_loading_date'] ?? '')) !== '' ? 'fact' : 'plan';
        }

        $endKind = trim((string) ($post['end_date_kind'] ?? ''));
        if (!in_array($endKind, ['plan', 'fact'], true)) {
            if (trim((string) ($post['actual_unloading_date'] ?? '')) !== '') {
                $post['end_date_kind'] = 'fact';
            } elseif (trim((string) ($post['planned_unloading_date'] ?? '')) !== '') {
                $post['end_date_kind'] = 'plan';
            } else {
                $post['end_date_kind'] = '';
            }
        }

        return $post;
    }

    /**
     * The carrier is part of the selected route executor (crew) and must not be
     * trusted as an independent user-entered value.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $sessionUser
     * @return array<string,mixed>
     */
    public static function deriveCarrierFromExecutor(
        array $config,
        Database $centralDb,
        int $companyId,
        array $sessionUser,
        array $post
    ): array {
        $post = self::normalizeDateKinds($post);
        $executorId = (int) ($post['route_executor_id'] ?? 0);
        if ($companyId <= 0 || $executorId <= 0) {
            $post['carrier_contractor_id'] = '';
            return $post;
        }

        try {
            $centralPdo = $centralDb->connection();
            $companyStmt = $centralPdo->prepare('SELECT * FROM companies WHERE id = ? AND status = ? LIMIT 1');
            $companyStmt->execute([$companyId, 'active']);
            $company = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($company === null) {
                $post['carrier_contractor_id'] = '';
                return $post;
            }

            $localDbConfig = \companyDatabaseConfig($config, $company);
            $localDb = new Database($localDbConfig);
            $localPdo = $localDb->connection();

            foreach (LinearRouteService::fetchVisibleRouteExecutors($localPdo, $sessionUser) as $executor) {
                if ((int) ($executor['id'] ?? 0) !== $executorId) {
                    continue;
                }

                $carrierId = (int) ($executor['contractor_id'] ?? 0);
                $post['carrier_contractor_id'] = $carrierId > 0 ? (string) $carrierId : '';
                return $post;
            }
        } catch (Throwable $e) {
            error_log(sprintf(
                '[P15] unable to derive carrier from executor company=%d executor=%d: %s',
                $companyId,
                $executorId,
                $e->getMessage()
            ));
        }

        $post['carrier_contractor_id'] = '';
        return $post;
    }

    /**
     * Fill missing modern condition_type values from legacy payment fields so
     * an existing route can be saved without re-entering historical terms.
     *
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    public static function normalizeRoutePaymentConditions(array $route): array
    {
        foreach (['customer', 'carrier'] as $scope) {
            foreach ((array) ($route['payments'][$scope] ?? []) as $index => $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                $route['payments'][$scope][$index] = self::normalizePaymentRow($payment);
            }
        }

        foreach ((array) ($route['payments']['principals'] ?? []) as $principalId => $payments) {
            foreach ((array) $payments as $index => $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                $route['payments']['principals'][$principalId][$index] = self::normalizePaymentRow($payment);
            }
        }

        return $route;
    }

    /** @param array<string,mixed> $payment @return array<string,mixed> */
    private static function normalizePaymentRow(array $payment): array
    {
        $conditionType = trim((string) ($payment['condition_type'] ?? ''));
        if (in_array($conditionType, LinearRouteService::CONDITION_TYPES, true)) {
            return $payment;
        }

        $legacy = trim((string) ($payment['payment_due_type'] ?? ''));
        $map = [
            // Legacy "Предоплата на загрузке" means payment on the route start day.
            // The modern prepayment condition requires a separate concrete date.
            'Предоплата' => DateCalculationService::CONDITION_START_DAY,
            'Предоплата на загрузке' => DateCalculationService::CONDITION_START_DAY,
            'В день начала рейса' => DateCalculationService::CONDITION_START_DAY,
            'После начала рейса' => DateCalculationService::CONDITION_AFTER_START,
            'После загрузки' => DateCalculationService::CONDITION_AFTER_START,
            'В день окончания рейса' => DateCalculationService::CONDITION_END_DAY,
            'До выгрузки' => DateCalculationService::CONDITION_END_DAY,
            'После окончания рейса' => DateCalculationService::CONDITION_AFTER_END,
            'После выгрузки' => DateCalculationService::CONDITION_AFTER_END,
            'После получения документов' => DateCalculationService::CONDITION_AFTER_DOCUMENTS,
            'Конкретная дата' => DateCalculationService::CONDITION_SPECIFIC_DATE,
        ];

        if (isset($map[$legacy])) {
            $payment['condition_type'] = $map[$legacy];
        }

        if (!array_key_exists('days_count', $payment) && array_key_exists('payment_due_days', $payment)) {
            $payment['days_count'] = $payment['payment_due_days'];
        }
        if (!array_key_exists('days_kind', $payment) && array_key_exists('payment_due_days_kind', $payment)) {
            $payment['days_kind'] = $payment['payment_due_days_kind'];
        }

        return $payment;
    }
}
