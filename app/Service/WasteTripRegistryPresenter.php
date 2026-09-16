<?php

namespace App\Service;

/** Read-only formatting for the Waste Trips registry. */
final class WasteTripRegistryPresenter
{
    public static function manager(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        return count($parts) > 1 ? $parts[1] . ' ' . mb_substr($parts[0], 0, 1, 'UTF-8') . '.' : ($parts[0] ?? '—');
    }

    public static function drivers(string $names): string
    {
        return implode(' / ', array_map(static function (string $name): string {
            $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
            $surname = array_shift($parts) ?? '';
            return trim($surname . ' ' . implode(' ', array_map(static fn(string $part): string => mb_substr($part, 0, 1, 'UTF-8') . '.', array_slice($parts, 0, 2))));
        }, preg_split('/\s*\/\s*/u', trim($names))));
    }

    public static function executorUnmatched(array $trip): bool
    {
        if (!empty($trip['route_executor_id']) || !in_array($trip['source_system'] ?? '', ['legacy_flights', 'legacy_sync'], true) || empty($trip['legacy_flight_id'])) return false;
        // Existing sync warnings also work before the next reconciliation. Unrelated
        // utilizer/source failures must never turn an empty executor into a mismatch.
        $warning = (string) ($trip['legacy_sync_error'] ?? '');
        foreach (['Исполнитель не найден по фамилии и госномеру.', 'Найдено несколько исполнителей с этой фамилией и госномером.', 'Не удалось определить фамилию или единственный госномер водителя старого рейса.'] as $message) {
            if (str_contains($warning, $message)) return true;
        }
        return false;
    }

    public static function cents(array $payments): int
    {
        $total = 0;
        foreach ($payments as $payment) {
            $amount = LinearRouteService::parseAmount((string) ($payment['amount'] ?? ''));
            if ($amount === null) continue;
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
            $total += (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }
        return $total;
    }

    public static function profitability(array $customer, array $carrier): ?string
    {
        if ($customer === [] || $carrier === []) return null;
        $sale = self::cents($customer);
        if ($sale <= 0) return null;
        return number_format((($sale - self::cents($carrier)) / $sale) * 100, 1, ',', ' ') . ' %';
    }
}
