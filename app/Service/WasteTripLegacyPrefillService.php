<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class WasteTripLegacyPrefillService
{
    public const SOURCE_SYSTEM = 'legacy_prefill';

    public static function createFromLegacyPrefill(PDO $legacyPdo, PDO $pdo, int $legacyId, array $input, array $requestBusinessIds, array $sessionUser, ?array $customerPayments = null): int
    {
        if ($legacyId < WasteTripLegacySourceService::MIN_LEGACY_ID) {
            throw new RuntimeException('Legacy ID должен быть больше 243.');
        }
        if (WasteTripLegacySourceService::fetchFlight($legacyPdo, $legacyId) === null) {
            throw new RuntimeException('Legacy-рейс #' . $legacyId . ' не найден.');
        }

        return WasteTripService::createLegacyPrefill($pdo, $legacyId, $input, $requestBusinessIds, $sessionUser, $customerPayments);
    }
}
