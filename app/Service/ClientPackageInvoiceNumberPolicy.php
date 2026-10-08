<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Historical outgoing invoice numbers that predate the YY-trip numbering rule. */
final class ClientPackageInvoiceNumberPolicy
{
    private const HISTORICAL = [
        244 => ['number' => '26-106-1', 'amount' => '275000.00'],
        246 => ['number' => '26-105', 'amount' => '180000.00'],
        255 => ['number' => '26-106', 'amount' => '404827.00'],
    ];

    public static function historical(int $businessId): ?array
    {
        return self::HISTORICAL[$businessId] ?? null;
    }

    public static function number(int $businessId, string $amount): string
    {
        if ($businessId <= 0) throw new RuntimeException('Номер рейса не определён.');
        $historical = self::historical($businessId);
        if ($historical !== null) {
            if ($amount !== $historical['amount']) {
                throw new RuntimeException('Сумма рейса #' . $businessId . ' не совпадает с историческим счётом № ' . $historical['number'] . ' (' . $historical['amount'] . ' ₽).');
            }
            return $historical['number'];
        }
        return date('y') . '-' . $businessId;
    }

    public static function tripDocumentNumber(int $businessId): string
    {
        $historical = self::historical($businessId);
        return ($historical === null ? date('y') : substr($historical['number'], 0, 2)) . '-' . $businessId;
    }

    public static function existingHistoricalInvoice(PDO $pdo, int $businessId, int $tripId, int $clientId, bool $lock = false): ?array
    {
        $historical = self::historical($businessId);
        if ($historical === null) return null;
        $query = $pdo->prepare(
            "SELECT i.* FROM finance_invoices i
              WHERE i.direction='OUTGOING' AND i.number=? AND i.amount=?
                AND i.counterparty_entity_type='client' AND i.counterparty_entity_id=?
                AND i.cancelled_at IS NULL AND i.status<>'cancelled'
                AND EXISTS (SELECT 1 FROM finance_invoice_links l
                      JOIN finance_obligations o ON o.id=l.obligation_id
                     WHERE l.invoice_id=i.id AND o.source_parent_type='WASTE_TRIP'
                       AND o.source_parent_id=? AND o.direction='RECEIVABLE'
                       AND o.party_role='customer')
              LIMIT 2" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$historical['number'], $historical['amount'], $clientId, $tripId]);
        $matches = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1) {
            throw new RuntimeException('Для рейса #' . $businessId . ' не найден единственный действующий счёт № '
                . $historical['number'] . ' на ' . $historical['amount'] . ' ₽, связанный с обязательством этого рейса. Проверьте номер и связь счёта в финансах.');
        }
        return $matches[0];
    }
}
