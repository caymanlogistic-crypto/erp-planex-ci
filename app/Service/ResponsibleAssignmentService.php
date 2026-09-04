<?php

namespace App\Service;

final class ResponsibleAssignmentService
{
    public const ENTITY_TYPES = [
        'client' => 'client',
        'contractor' => 'contractor',
        'driver' => 'driver',
        'vehicle_unit' => 'vehicle_unit',
    ];

    public static function normalizeEntityType(?string $type): ?string
    {
        $type = trim((string) $type);

        if ($type === '') {
            return null;
        }

        return self::ENTITY_TYPES[$type] ?? null;
    }
}
