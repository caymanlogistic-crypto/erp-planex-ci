<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class WasteTripCustomerPaymentService
{
    public static function parseRows(array $rows, bool $required = true, bool $allowUnknownAmount = false): array
    {
        $result = [];
        $errors = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) continue;

            $amountRaw = trim((string) ($row['amount'] ?? ''));
            if ($amountRaw === '' && !$allowUnknownAmount) continue;
            $row += ['condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar'];
            $paymentMethod = trim((string) ($row['payment_method'] ?? ''));
            $vatRateRaw = trim((string) ($row['vat_rate'] ?? ''));
            $conditionType = trim((string) ($row['condition_type'] ?? ''));
            $daysCountRaw = trim((string) ($row['days_count'] ?? ''));
            $daysKind = trim((string) ($row['days_kind'] ?? ''));
            $specificDueDateRaw = trim((string) ($row['specific_due_date'] ?? ''));
            $conditionComment = trim((string) ($row['condition_comment'] ?? ''));

            if ($amountRaw === '' && $paymentMethod === '' && $conditionType === '' && $daysCountRaw === '' && $daysKind === '' && $specificDueDateRaw === '' && $conditionComment === '') continue;

            $amount = LinearRouteService::parseAmount($amountRaw);
            if ($amount === null && !($allowUnknownAmount && $amountRaw === '')) $errors[] = 'Укажите корректную сумму оплаты заказчика.';
            if (!in_array($paymentMethod, ['', 'unknown', 'cashless', 'cash'], true)) $errors[] = 'Выберите способ оплаты заказчика.';

            $vatRate = null;
            if ($vatRateRaw !== '' && $vatRateRaw !== 'unknown') {
                if (in_array($vatRateRaw, ['0', '5', '7', '20', '22'], true)) $vatRate = $vatRateRaw;
                else $errors[] = 'Выберите корректную ставку НДС для оплаты заказчика.';
            }

            if (!in_array($conditionType, LinearRouteService::CONDITION_TYPES, true)) {
                $errors[] = 'Выберите срок оплаты заказчика.';
            }

            $daysCount = null;
            if (LinearRouteService::conditionTypeRequiresDays($conditionType)) {
                if ($daysCountRaw === '' || !ctype_digit($daysCountRaw) || (int) $daysCountRaw <= 0) {
                    $errors[] = 'Укажите количество дней для срока оплаты заказчика.';
                } else {
                    $daysCount = (int) $daysCountRaw;
                }
                if (!array_key_exists($daysKind, LinearRouteService::PAYMENT_DUE_DAYS_KINDS)) {
                    $errors[] = 'Выберите тип дней для срока оплаты заказчика.';
                }
            } else {
                $daysKind = null;
            }

            $specificDueDate = null;
            if (LinearRouteService::conditionTypeRequiresSpecificDate($conditionType)) {
                $specificDueDate = LinearRouteService::normalizeDate($specificDueDateRaw);
                if ($specificDueDate === null) $errors[] = 'Укажите конкретную дату оплаты заказчика.';
            }

            $result[] = [
                'amount' => $amount,
                'payment_type' => $paymentMethod === 'unknown' || $vatRateRaw === 'unknown' ? '' : LinearRouteService::legacyPaymentTypeFromMethodAndVat($paymentMethod !== '' ? $paymentMethod : 'cashless', $vatRate),
                'payment_method' => $paymentMethod === 'unknown' ? '' : ($paymentMethod !== '' ? $paymentMethod : 'cashless'),
                'vat_rate' => $vatRate,
                'condition_type' => $conditionType,
                'days_count' => $daysCount,
                'days_kind' => $daysKind,
                'specific_due_date' => $specificDueDate,
                'condition_comment' => $conditionComment !== '' ? $conditionComment : null,
            ];
        }

        if ($required && $result === []) $errors[] = 'Добавьте ставку заказчика и срок оплаты.';
        if ($errors !== []) throw new RuntimeException(implode(' ', array_values(array_unique($errors))));
        return $result;
    }

    public static function fetchForTrip(PDO $pdo, int $tripId): array
    {
        if ($tripId <= 0) return [];
        $stmt = $pdo->prepare('SELECT * FROM waste_trip_customer_payments WHERE waste_trip_id=? ORDER BY sort_order,id');
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchForTrips(PDO $pdo, array $tripIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tripIds))));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM waste_trip_customer_payments WHERE waste_trip_id IN ({$marks}) ORDER BY waste_trip_id,sort_order,id");
        $stmt->execute($ids);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $grouped[(int) $row['waste_trip_id']][] = $row;
        return $grouped;
    }

    public static function store(PDO $pdo, int $tripId, array $payments, array $sessionUser): void
    {
        if ($tripId <= 0) throw new RuntimeException('Некорректный рейс для сохранения ставки заказчика.');
        $oldPayments = self::fetchForTrip($pdo, $tripId);
        $pdo->prepare('DELETE FROM waste_trip_customer_payments WHERE waste_trip_id=?')->execute([$tripId]);
        $stmt = $pdo->prepare('INSERT INTO waste_trip_customer_payments (waste_trip_id,sort_order,amount,payment_type,payment_method,vat_rate,condition_type,days_count,days_kind,specific_due_date,condition_comment,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $actorUserId = (int) ($sessionUser['user_id'] ?? 0);
        $actorRole = trim((string) ($sessionUser['role_code'] ?? ''));
        $storedUserId = $actorUserId > 0 ? $actorUserId : null;
        $storedRole = $actorRole !== '' ? $actorRole : null;
        foreach (array_values($payments) as $index => $payment) {
            $stmt->execute([
                $tripId,
                $index + 1,
                $payment['amount'],
                $payment['payment_type'],
                $payment['payment_method'],
                $payment['vat_rate'],
                $payment['condition_type'],
                $payment['days_count'],
                $payment['days_kind'],
                $payment['specific_due_date'],
                $payment['condition_comment'],
                $storedUserId,
                $storedRole,
                $storedUserId,
                $storedRole,
            ]);
        }
        if ($actorUserId > 0 && $actorRole !== '') {
            LinearRouteService::logFinanceAudit(
                $pdo,
                'waste_trip',
                $tripId,
                'customer_payments_update',
                ['payments' => $oldPayments],
                ['payments' => self::fetchForTrip($pdo, $tripId)],
                $actorUserId,
                $actorRole
            );
        }
    }

    public static function total(array $payments): string
    {
        if ($payments === [] || count(array_filter($payments, static fn(array $p): bool => $p['amount'] !== null && $p['amount'] !== '')) === 0) return '—';
        $cents = 0;
        foreach ($payments as $payment) {
            $amount = LinearRouteService::parseAmount((string) ($payment['amount'] ?? ''));
            if ($amount === null) continue;
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
            $cents += ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }
        return number_format(intdiv($cents, 100), 0, ',', ' ') . ' ₽';
    }

    public static function synchronizeCost(PDO $pdo, int $tripId, ?string $amount, ?string $terms, array $actor): void
    {
        WasteTripPaymentSyncService::synchronize($pdo, $tripId, 'customer', $amount, $terms, $actor);
    }

    /** The append-only trip audit retains this irreversible per-field ownership change. */
    public static function amountSyncDisabled(PDO $pdo, int $tripId): bool
    {
        $query = $pdo->prepare("SELECT id FROM waste_trip_audit_log WHERE waste_trip_id=? AND action='update' AND JSON_UNQUOTE(JSON_EXTRACT(after_json, '$.customer_amount_sync_disabled'))='true' LIMIT 1");
        $query->execute([$tripId]);
        return $query->fetchColumn() !== false;
    }

    public static function detail(array $payment): string
    {
        $parts = [$payment['amount'] === null ? '—' : number_format((float) $payment['amount'], 0, ',', ' ') . ' ₽'];
        $condition = LinearRouteService::conditionTypeLabel($payment['condition_type'] ?? null);
        if ($condition !== '' && $condition !== '—') $parts[] = $condition;
        $daysCount = (int) ($payment['days_count'] ?? 0);
        if ($daysCount > 0) $parts[] = $daysCount . ' ' . (($payment['days_kind'] ?? 'calendar') === 'working' ? 'РД' : 'БД');
        if (!empty($payment['specific_due_date'])) {
            $date = (string) $payment['specific_due_date'];
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) $date = $m[3] . '.' . $m[2] . '.' . $m[1];
            $parts[] = $date;
        }
        return implode(' · ', $parts);
    }
}
