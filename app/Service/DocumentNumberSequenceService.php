<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class DocumentNumberSequenceService
{
    public const VEHICLE_LEASE_CONTRACT_KEY = 'vehicle_lease_crew_contract';
    public const VEHICLE_LEASE_CONTRACT_PREFIX = 'П ';
    public const VEHICLE_LEASE_CONTRACT_FIRST = 105;

    public static function allocateVehicleLeaseContractNumber(PDO $pdo): string
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Автоматический номер договора должен выделяться внутри транзакции.');
        }

        $stmt = $pdo->prepare(
            'SELECT prefix,next_number FROM document_number_sequences WHERE sequence_key=? FOR UPDATE'
        );
        $stmt->execute([self::VEHICLE_LEASE_CONTRACT_KEY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            throw new RuntimeException('Последовательность номеров договоров не инициализирована. Требуется tenant migration 129.');
        }

        $prefix = (string)($row['prefix'] ?? '');
        $next = (int)($row['next_number'] ?? 0);
        if ($prefix !== self::VEHICLE_LEASE_CONTRACT_PREFIX || $next < self::VEHICLE_LEASE_CONTRACT_FIRST) {
            throw new RuntimeException('Последовательность номеров договоров повреждена или имеет неожиданные параметры.');
        }

        $update = $pdo->prepare(
            'UPDATE document_number_sequences SET next_number=next_number+1 WHERE sequence_key=? AND next_number=?'
        );
        $update->execute([self::VEHICLE_LEASE_CONTRACT_KEY, $next]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Не удалось атомарно зарезервировать номер договора.');
        }

        return $prefix . $next;
    }
}
