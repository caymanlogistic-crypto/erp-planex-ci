<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class WasteTripCarrierPaymentService
{
    public static function parseRows(array $rows, bool $required = false): array
    {
        $result = [];
        $errors = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) continue;

            $amountRaw = trim((string) ($row['amount'] ?? ''));
            $paymentMethod = trim((string) ($row['payment_method'] ?? ''));
            $vatRateRaw = trim((string) ($row['vat_rate'] ?? ''));
            $conditionType = trim((string) ($row['condition_type'] ?? ''));
            $daysCountRaw = trim((string) ($row['days_count'] ?? ''));
            $daysKind = trim((string) ($row['days_kind'] ?? ''));
            $specificDueDateRaw = trim((string) ($row['specific_due_date'] ?? ''));
            $conditionComment = trim((string) ($row['condition_comment'] ?? ''));

            if ($amountRaw === '' && $paymentMethod === '' && $conditionType === '' && $daysCountRaw === '' && $daysKind === '' && $specificDueDateRaw === '' && $conditionComment === '') continue;

            $amount = LinearRouteService::parseAmount($amountRaw);
            if ($amount === null) $errors[] = 'Укажите корректную сумму оплаты перевозчика.';
            if (!in_array($paymentMethod, ['', 'cashless', 'cash'], true)) $errors[] = 'Выберите способ оплаты перевозчика.';

            $vatRate = null;
            if ($vatRateRaw !== '') {
                if (in_array($vatRateRaw, ['0', '5', '7', '20', '22'], true)) $vatRate = $vatRateRaw;
                else $errors[] = 'Выберите корректную ставку НДС для оплаты перевозчика.';
            }

            if (!in_array($conditionType, LinearRouteService::CONDITION_TYPES, true)) {
                $errors[] = 'Выберите срок оплаты перевозчика.';
            }

            $daysCount = null;
            if (LinearRouteService::conditionTypeRequiresDays($conditionType)) {
                if ($daysCountRaw === '' || !ctype_digit($daysCountRaw) || (int) $daysCountRaw <= 0) {
                    $errors[] = 'Укажите количество дней для срока оплаты перевозчика.';
                } else {
                    $daysCount = (int) $daysCountRaw;
                }
                if (!array_key_exists($daysKind, LinearRouteService::PAYMENT_DUE_DAYS_KINDS)) {
                    $errors[] = 'Выберите тип дней для срока оплаты перевозчика.';
                }
            } else {
                $daysKind = null;
            }

            $specificDueDate = null;
            if (LinearRouteService::conditionTypeRequiresSpecificDate($conditionType)) {
                $specificDueDate = LinearRouteService::normalizeDate($specificDueDateRaw);
                if ($specificDueDate === null) $errors[] = 'Укажите конкретную дату оплаты перевозчика.';
            }

            $result[] = [
                'amount' => $amount,
                'payment_type' => LinearRouteService::legacyPaymentTypeFromMethodAndVat($paymentMethod !== '' ? $paymentMethod : 'cashless', $vatRate),
                'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'cashless',
                'vat_rate' => $vatRate,
                'condition_type' => $conditionType,
                'days_count' => $daysCount,
                'days_kind' => $daysKind,
                'specific_due_date' => $specificDueDate,
                'condition_comment' => $conditionComment !== '' ? $conditionComment : null,
            ];
        }

        if ($required && $result === []) $errors[] = 'Добавьте ставку перевозчика и срок оплаты.';
        if ($errors !== []) throw new RuntimeException(implode(' ', array_values(array_unique($errors))));
        return $result;
    }

    public static function fetchForTrip(PDO $pdo, int $tripId): array
    {
        if ($tripId <= 0) return [];
        $stmt = $pdo->prepare('SELECT * FROM waste_trip_carrier_payments WHERE waste_trip_id=? ORDER BY sort_order,id');
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchForTrips(PDO $pdo, array $tripIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tripIds))));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM waste_trip_carrier_payments WHERE waste_trip_id IN ({$marks}) ORDER BY waste_trip_id,sort_order,id");
        $stmt->execute($ids);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $grouped[(int) $row['waste_trip_id']][] = $row;
        return $grouped;
    }

    public static function store(PDO $pdo, int $tripId, array $payments, array $sessionUser): void
    {
        if ($tripId <= 0) throw new RuntimeException('Некорректный рейс для сохранения ставки перевозчика.');
        $oldPayments = self::fetchForTrip($pdo, $tripId);
        $pdo->prepare('DELETE FROM waste_trip_carrier_payments WHERE waste_trip_id=?')->execute([$tripId]);
        $stmt = $pdo->prepare('INSERT INTO waste_trip_carrier_payments (waste_trip_id,sort_order,amount,payment_type,payment_method,vat_rate,condition_type,days_count,days_kind,specific_due_date,condition_comment,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
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
                'carrier_payments_update',
                ['payments' => $oldPayments],
                ['payments' => self::fetchForTrip($pdo, $tripId)],
                $actorUserId,
                $actorRole
            );
        }
    }

    public static function total(array $payments): string
    {
        $cents = 0;
        foreach ($payments as $payment) {
            $amount = LinearRouteService::parseAmount((string) ($payment['amount'] ?? ''));
            if ($amount === null) continue;
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
            $cents += ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }
        return number_format(intdiv($cents, 100), 0, ',', ' ') . ' ₽';
    }

    /** One source total; existing single-payment terms stay owned by ERP. */
    public static function synchronizeCost(PDO $pdo, int $tripId, ?string $amount, ?string $terms, array $actor): void
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Ставка синхронизируется в транзакции рейса.');
        $old = self::fetchForTrip($pdo,$tripId);
        $cents = static fn(string $value): int => (int)str_replace('.', '', $value);
        $total = array_sum(array_map(static fn(array $row): int => $cents((string)$row['amount']), $old));
        // Retain an existing installment schedule when it already equals the source total.
        if ($amount !== null && $old !== [] && $total === $cents($amount)) return;
        if ($amount === null) {
            $pdo->prepare('DELETE FROM waste_trip_carrier_payments WHERE waste_trip_id=?')->execute([$tripId]);
        } elseif (count($old) === 1) {
            $pdo->prepare('UPDATE waste_trip_carrier_payments SET amount=?,updated_by_user_id=?,updated_by_role=? WHERE id=?')->execute([$amount,$actor['user_id'],$actor['role_code'],$old[0]['id']]);
        } else {
            // An aggregate source price has no installment dates or tax/payment method.
            // Keep the first row identity/sort key; audit retains the former schedule.
            $first = $old[0] ?? null;
            if ($first !== null) {
                $removedIds = array_map('intval',array_column(array_slice($old,1),'id'));
                $marks = implode(',',array_fill(0,count($removedIds),'?'));
                $linked = $pdo->prepare("SELECT COUNT(*) FROM finance_obligations o WHERE o.source_type='WASTE_TRIP_CARRIER_PAYMENT' AND o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=? AND o.source_id IN ({$marks}) AND (
                    EXISTS(SELECT 1 FROM finance_operation_allocations a JOIN finance_operations op ON op.id=a.operation_id WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND op.status='POSTED')
                    OR EXISTS(SELECT 1 FROM finance_invoice_links l WHERE l.obligation_id=o.id))");
                $linked->execute(array_merge([$tripId],$removedIds));
                if ((int)$linked->fetchColumn() > 0) throw new RuntimeException('Ставка отличается от старой БД, а разбивка оплаты связана со счетами или проведёнными платежами. Требуется согласовать разбивку оплаты.');
                $pdo->prepare("UPDATE waste_trip_carrier_payments SET amount=?,payment_type='',payment_method='',vat_rate=NULL,condition_type='',days_count=NULL,days_kind=NULL,specific_due_date=NULL,condition_comment=?,updated_by_user_id=?,updated_by_role=? WHERE id=?")
                    ->execute([$amount,$terms,$actor['user_id'],$actor['role_code'],$first['id']]);
                $pdo->prepare('DELETE FROM waste_trip_carrier_payments WHERE waste_trip_id=? AND id<>?')->execute([$tripId,$first['id']]);
            } else {
                $pdo->prepare("INSERT INTO waste_trip_carrier_payments (waste_trip_id,sort_order,amount,payment_type,payment_method,condition_type,condition_comment,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,1,?,'','','',?,?,?,?,?)")
                    ->execute([$tripId,$amount,$terms,$actor['user_id'],$actor['role_code'],$actor['user_id'],$actor['role_code']]);
            }
        }
        LinearRouteService::logFinanceAudit($pdo,'waste_trip',$tripId,'carrier_payments_update',['payments'=>$old],['payments'=>self::fetchForTrip($pdo,$tripId),'source'=>'legacy_sync'],(int)$actor['user_id'],(string)$actor['role_code']);
    }

    public static function detail(array $payment): string
    {
        $parts = [number_format((float) ($payment['amount'] ?? 0), 0, ',', ' ') . ' ₽'];
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
