<?php

namespace App\Service;

use PDO;

final class FinanceInvoiceService
{
    public const DIRECTION_OUTGOING = 'OUTGOING';
    public const DIRECTION_INCOMING = 'INCOMING';

    public const STATUSES = [
        'draft' => 'Черновик',
        'issued' => 'Выставлен',
        'received' => 'Получен',
        'partially_paid' => 'Частично оплачен',
        'paid' => 'Оплачен',
        'overdue' => 'Просрочен',
        'overdue_partial' => 'Частично оплачен, просрочен',
        'cancelled' => 'Аннулирован',
    ];

    public const DIRECTIONS = [
        self::DIRECTION_OUTGOING => 'Выставленный',
        self::DIRECTION_INCOMING => 'Полученный',
    ];

    public static function directionLabel(?string $direction): string
    {
        return self::DIRECTIONS[$direction] ?? '—';
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUSES[$status] ?? '—';
    }

    public static function statusBadgeClass(?string $status): string
    {
        return match ($status) {
            'draft' => 'badge badge-neutral',
            'issued', 'received' => 'badge badge-ok',
            'partially_paid' => 'badge badge-warning',
            'overdue_partial' => 'badge badge-danger',
            'paid' => 'badge badge-ok',
            'overdue' => 'badge badge-danger',
            'cancelled' => 'badge badge-neutral',
            default => 'badge badge-neutral',
        };
    }

    public static function normalizeMoneyInput(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], $value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            return null;
        }
        $parts = explode('.', $normalized);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $decPart = str_pad($parts[1] ?? '0', 2, '0');
        if ($intPart === '0' && $decPart === '00') {
            return null;
        }
        return $intPart . '.' . $decPart;
    }

    public static function isVatRateInputValid(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return true;
        }
        $allowed = ['0', '5', '7', '20', '22'];
        return in_array((string) $value, $allowed, true);
    }

    public static function normalizeVatRateInput(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return ((string) $value) . '.00';
    }

    public static function formatAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $normalized = str_replace(',', '.', (string) $value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return '—';
        }
        $parts = explode('.', $normalized);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '' || $intPart === '-') {
            $intPart = $intPart === '-' ? '-0' : '0';
        }
        $decPart = str_pad(substr(($parts[1] ?? ''), 0, 2), 2, '0');
        $formattedInt = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $intPart);
        return $formattedInt . ',' . $decPart;
    }

    public static function normalizeCounterpartyInput(
        PDO $localPdo,
        mixed $rawType,
        mixed $rawId,
        string $rawName,
        string $rawInn
    ): array {
        $errors = [];
        $type = null;
        $id = null;
        $name = trim($rawName);
        $inn = trim($rawInn);

        if ($rawType !== null && $rawType !== '' && $rawType !== false) {
            $type = trim((string) $rawType);
        }

        if ($type !== null && !in_array($type, ['client', 'contractor'], true)) {
            $errors[] = 'Некорректный тип контрагента.';
            $type = null;
        }

        if ($rawId !== null && $rawId !== '' && $rawId !== false) {
            $id = (int) $rawId;
            if ($id <= 0) {
                $errors[] = 'Некорректный ID контрагента.';
                $id = null;
            }
        }

        if ($type !== null && $id === null) {
            $errors[] = 'Выберите контрагента из списка.';
        }

        if ($type === null && $name === '') {
            $errors[] = 'Укажите контрагента.';
        }

        if ($type === 'client' && $id !== null) {
            $stmt = $localPdo->prepare(
                "SELECT id, name, inn FROM clients WHERE id = ? AND status = 'active' AND deleted_at IS NULL"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $errors[] = 'Выбранный клиент не найден или недоступен.';
            } else {
                $name = $row['name'];
                $inn = $row['inn'] ?? '';
            }
        } elseif ($type === 'contractor' && $id !== null) {
            $stmt = $localPdo->prepare(
                "SELECT id, name, inn FROM contractors WHERE id = ? AND status = 'active' AND deleted_at IS NULL"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $errors[] = 'Выбранный подрядчик не найден или недоступен.';
            } else {
                $name = $row['name'];
                $inn = $row['inn'] ?? '';
            }
        }

        return [
            'errors' => $errors,
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'inn' => $inn,
        ];
    }

    public static function fetchInvoices(PDO $localPdo, array $user, ?string $direction = null, int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;

        $baseWhere = "WHERE fi.cancelled_at IS NULL AND fi.status <> 'cancelled'";
        $countWhere = "WHERE fi.cancelled_at IS NULL AND fi.status <> 'cancelled'";
        $params = [];
        $countParams = [];

        if ($direction !== null && $direction !== '') {
            $baseWhere .= " AND fi.direction = ?";
            $params[] = $direction;
            $countWhere .= " AND fi.direction = ?";
            $countParams[] = $direction;
        }

        $roleCode = (string) ($user['role_code'] ?? '');
        if ($roleCode === 'logist') {
            $userId = (int) ($user['user_id'] ?? 0);
            $accessFilter = " AND (fi.created_by_user_id = ?
                        OR fi.id IN (
                            SELECT fil.invoice_id
                              FROM finance_invoice_links fil
                              JOIN linear_routes lr ON lr.id = fil.linear_route_id
                             WHERE (lr.created_by_user_id = ?
                                OR lr.id IN (
                                    SELECT entity_id
                                      FROM entity_access_grants
                                     WHERE entity_type = 'linear_route'
                                       AND granted_to_user_id = ?
                                       AND access_level IN ('view','edit')
                                       AND revoked_at IS NULL
                                ))
                        ))";
            $baseWhere .= $accessFilter;
            $params[] = $userId;
            $params[] = $userId;
            $params[] = $userId;
            $countWhere .= $accessFilter;
            $countParams[] = $userId;
            $countParams[] = $userId;
            $countParams[] = $userId;
        }

        $selectSql = "SELECT fi.*,
                       COALESCE((SELECT SUM(foa.amount)
                                   FROM finance_operation_allocations foa
                                  WHERE foa.invoice_id = fi.id
                                    AND foa.cancelled_at IS NULL), 0) AS paid_amount,
                       (fi.amount - COALESCE((SELECT SUM(foa.amount)
                                                 FROM finance_operation_allocations foa
                                                WHERE foa.invoice_id = fi.id
                                                  AND foa.cancelled_at IS NULL), 0)) AS remaining_amount
                   FROM finance_invoices fi
                   {$baseWhere}
                   ORDER BY fi.created_at DESC
                   LIMIT ? OFFSET ?";

        $countSql = "SELECT COUNT(*) FROM finance_invoices fi {$countWhere}";
        $countStmt = $localPdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $localPdo->prepare($selectSql);
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    public static function fetchInvoiceById(PDO $localPdo, int $id): ?array
    {
        $stmt = $localPdo->prepare(
            "SELECT fi.*,
                    COALESCE((SELECT SUM(foa.amount)
                                FROM finance_operation_allocations foa
                               WHERE foa.invoice_id = fi.id
                                 AND foa.cancelled_at IS NULL), 0) AS paid_amount,
                    (fi.amount - COALESCE((SELECT SUM(foa.amount)
                                             FROM finance_operation_allocations foa
                                            WHERE foa.invoice_id = fi.id
                                              AND foa.cancelled_at IS NULL), 0)) AS remaining_amount
               FROM finance_invoices fi
              WHERE fi.id = ?"
        );
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        return $invoice !== false ? $invoice : null;
    }

    public static function fetchInvoiceLinks(PDO $localPdo, int $invoiceId): array
    {
        $stmt = $localPdo->prepare(
            "SELECT fil.*,
                    lr.route_type, lr.planned_loading_date,
                    lrp.amount AS payment_amount,
                    lrp.party_role AS payment_party_role
               FROM finance_invoice_links fil
          LEFT JOIN linear_routes lr ON lr.id = fil.linear_route_id
          LEFT JOIN linear_route_payments lrp ON lrp.id = fil.linear_route_payment_id
              WHERE fil.invoice_id = ?"
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createInvoice(PDO $localPdo, array $data, int $userId, string $roleCode): int
    {
        $stmt = $localPdo->prepare(
            "INSERT INTO finance_invoices (
                direction, number, invoice_date,
                counterparty_entity_type, counterparty_entity_id,
                counterparty_name, counterparty_inn,
                amount, vat_rate, basis,
                planned_payment_date, comment,
                status, paid_amount,
                created_by_user_id, created_by_role,
                updated_by_user_id, updated_by_role
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $data['direction'],
            $data['number'],
            $data['invoice_date'],
            $data['counterparty_entity_type'] ?? null,
            $data['counterparty_entity_id'] ?? null,
            $data['counterparty_name'] ?? null,
            $data['counterparty_inn'] ?? null,
            $data['amount'],
            $data['vat_rate'] ?? null,
            $data['basis'] ?? null,
            $data['planned_payment_date'] ?? null,
            $data['comment'] ?? null,
            $data['status'] ?? 'draft',
            '0.00',
            $userId,
            $roleCode,
            $userId,
            $roleCode,
        ]);

        $invoiceId = (int) $localPdo->lastInsertId();

        FinanceAuditLogService::log($localPdo, 'finance_invoice', $invoiceId, 'invoice_create', null, [
            'direction' => $data['direction'],
            'number' => $data['number'],
            'amount' => $data['amount'],
            'status' => $data['status'] ?? 'draft',
        ], $userId, $roleCode);

        return $invoiceId;
    }

    public static function updateInvoice(PDO $localPdo, int $id, array $data, int $userId, string $roleCode): void
    {
        $oldStmt = $localPdo->prepare("SELECT * FROM finance_invoices WHERE id = ?");
        $oldStmt->execute([$id]);
        $oldInvoice = $oldStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $localPdo->prepare(
            "UPDATE finance_invoices
                SET direction = ?,
                    number = ?,
                    invoice_date = ?,
                    counterparty_entity_type = ?,
                    counterparty_entity_id = ?,
                    counterparty_name = ?,
                    counterparty_inn = ?,
                    amount = ?,
                    vat_rate = ?,
                    basis = ?,
                    planned_payment_date = ?,
                    comment = ?,
                    status = ?,
                    updated_by_user_id = ?,
                    updated_by_role = ?
              WHERE id = ?"
        );

        $stmt->execute([
            $data['direction'],
            $data['number'],
            $data['invoice_date'],
            $data['counterparty_entity_type'] ?? null,
            $data['counterparty_entity_id'] ?? null,
            $data['counterparty_name'] ?? null,
            $data['counterparty_inn'] ?? null,
            $data['amount'],
            $data['vat_rate'] ?? null,
            $data['basis'] ?? null,
            $data['planned_payment_date'] ?? null,
            $data['comment'] ?? null,
            $data['status'] ?? 'draft',
            $userId,
            $roleCode,
            $id,
        ]);

        if ($oldInvoice) {
            FinanceAuditLogService::log($localPdo, 'finance_invoice', $id, 'invoice_update', [
                'direction' => $oldInvoice['direction'],
                'number' => $oldInvoice['number'],
                'amount' => $oldInvoice['amount'],
                'status' => $oldInvoice['status'],
                'planned_payment_date' => $oldInvoice['planned_payment_date'],
            ], [
                'direction' => $data['direction'],
                'number' => $data['number'],
                'amount' => $data['amount'],
                'status' => $data['status'] ?? 'draft',
                'planned_payment_date' => $data['planned_payment_date'] ?? null,
            ], $userId, $roleCode);
        }
    }

    public static function cancelInvoice(PDO $localPdo, int $id, int $userId, string $roleCode, ?string $reason = null): void
    {
        $cancelReason = $reason !== null ? trim($reason) : '';
        if ($cancelReason === '') {
            throw new \RuntimeException('Укажите причину отмены счёта.');
        }

        $oldStmt = $localPdo->prepare("SELECT * FROM finance_invoices WHERE id = ?");
        $oldStmt->execute([$id]);
        $oldInvoice = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldInvoice) {
            throw new \RuntimeException('Счёт не найден.');
        }
        if ($oldInvoice['cancelled_at'] !== null || ($oldInvoice['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('Счёт уже аннулирован. Повторная отмена запрещена.');
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $localPdo->prepare(
            "UPDATE finance_invoices
                SET status = 'cancelled',
                    cancelled_at = :cancelled_at,
                    cancellation_reason = :reason,
                    updated_by_user_id = :user_id,
                    updated_by_role = :role,
                    updated_at = NOW()
              WHERE id = :id
                AND status NOT IN ('cancelled')
                AND cancelled_at IS NULL"
        );
        $stmt->execute([
            ':cancelled_at' => $now,
            ':reason' => $cancelReason,
            ':user_id' => $userId,
            ':role' => $roleCode,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() > 0) {
            FinanceAuditLogService::log($localPdo, 'finance_invoice', $id, 'invoice_cancel', [
                'status' => $oldInvoice['status'],
                'amount' => $oldInvoice['amount'],
                'paid_amount' => $oldInvoice['paid_amount'],
            ], [
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancellation_reason' => $cancelReason,
            ], $userId, $roleCode);

            FinanceSettlementCascadeService::recalculateInvoice($localPdo, $id);
        }
    }

    public static function validateRouteLink(PDO $localPdo, ?int $routeId, ?int $paymentId, ?string $linkSide): ?string
    {
        if ($routeId === null) {
            return null;
        }

        $stmt = $localPdo->prepare(
            "SELECT id FROM linear_routes WHERE id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$routeId]);
        if (!$stmt->fetch()) {
            return 'Указанный рейс не найден.';
        }

        if ($paymentId !== null) {
            $stmt = $localPdo->prepare(
                "SELECT id, party_role FROM linear_route_payments WHERE id = ? AND linear_route_id = ? AND deleted_at IS NULL"
            );
            $stmt->execute([$paymentId, $routeId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                return 'Указанная платёжная строка не найдена или не принадлежит выбранному рейсу.';
            }
            if ($linkSide !== null && $linkSide !== '' && $payment['party_role'] !== $linkSide) {
                return 'Сторона привязки не соответствует роли платежной строки.';
            }
        }

        return null;
    }

    public static function createInvoiceLink(PDO $localPdo, int $invoiceId, array $linkData, int $userId, string $roleCode): int
    {
        $stmt = $localPdo->prepare(
            "INSERT INTO finance_invoice_links (
                invoice_id, linear_route_id, linear_route_payment_id,
                amount, side,
                created_by_user_id, created_by_role
            ) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $invoiceId,
            $linkData['linear_route_id'] ?? null,
            $linkData['linear_route_payment_id'] ?? null,
            $linkData['amount'] ?? null,
            $linkData['side'] ?? null,
            $userId,
            $roleCode,
        ]);

        return (int) $localPdo->lastInsertId();
    }

    public static function getInvoiceCountForRoute(PDO $localPdo, int $routeId): array
    {
        $stmt = $localPdo->prepare(
            "SELECT fil.side,
                    COUNT(DISTINCT fil.invoice_id) AS invoice_count,
                    COALESCE(SUM(fi.amount), 0) AS total_amount
               FROM finance_invoice_links fil
               JOIN finance_invoices fi ON fi.id = fil.invoice_id
              WHERE fil.linear_route_id = ?
                AND fi.status NOT IN ('cancelled')
              GROUP BY fil.side"
        );
        $stmt->execute([$routeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = ['customer' => 0, 'carrier' => 0, 'principal' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $side = (string) ($row['side'] ?? '');
            $count = (int) ($row['invoice_count'] ?? 0);
            if (isset($result[$side])) {
                $result[$side] = $count;
            }
            $result['total'] += $count;
        }

        return $result;
    }

    public static function fetchRoutesForSelect(PDO $localPdo, array $user): array
    {
        $baseSql = "SELECT lr.id, lr.route_type, lr.planned_loading_date,
                           ct.name AS client_name,
                           carrier.name AS carrier_name
                      FROM linear_routes lr
                      JOIN clients ct ON ct.id = lr.client_id
                      JOIN contractors carrier ON carrier.id = lr.carrier_contractor_id
                     WHERE lr.deleted_at IS NULL";

        $roleCode = (string) ($user['role_code'] ?? '');
        if ($roleCode === 'logist') {
            $userId = (int) ($user['user_id'] ?? 0);
            $stmt = $localPdo->prepare(
                $baseSql . " AND (
                    lr.created_by_user_id = ?
                    OR lr.id IN (
                        SELECT entity_id
                          FROM entity_access_grants
                         WHERE entity_type = 'linear_route'
                           AND granted_to_user_id = ?
                           AND access_level IN ('view','edit')
                           AND revoked_at IS NULL
                    )
                ) ORDER BY lr.created_at DESC"
            );
            $stmt->execute([$userId, $userId]);
        } else {
            $stmt = $localPdo->query($baseSql . " ORDER BY lr.created_at DESC");
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchRoutePaymentsForSelect(PDO $localPdo, int $routeId): array
    {
        $stmt = $localPdo->prepare(
            "SELECT lrp.id, lrp.party_role, lrp.amount,
                    lrp.payment_method, lrp.vat_rate,
                    lrp.payment_due_type, lrp.calculated_due_date,
                    lrp.payment_status
               FROM linear_route_payments lrp
              WHERE lrp.linear_route_id = ?
                AND lrp.deleted_at IS NULL
              ORDER BY lrp.sort_order ASC"
        );
        $stmt->execute([$routeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchClientsForSelect(PDO $localPdo): array
    {
        $stmt = $localPdo->query(
            "SELECT id, name, inn
               FROM clients
              WHERE status = 'active'
                AND deleted_at IS NULL
              ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchContractorsForSelect(PDO $localPdo): array
    {
        $stmt = $localPdo->query(
            "SELECT id, name, inn
               FROM contractors
              WHERE status = 'active'
                AND deleted_at IS NULL
              ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
