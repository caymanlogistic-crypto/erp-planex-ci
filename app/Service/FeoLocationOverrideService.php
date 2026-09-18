<?php

namespace App\Service;

use PDO;

/** Used by imports so locally moved locations survive subsequent customer exports. */
final class FeoLocationOverrideService
{
    public const FIELDS = ['mno_adres_pogruzki','mno_sh','mno_d'];

    public static function activeIds(PDO $pdo): array
    {
        if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_location_overrides'")->fetchColumn()) return [];
        return array_fill_keys($pdo->query('SELECT zayavka_id FROM feo_location_overrides')->fetchAll(PDO::FETCH_COLUMN), true);
    }

    public static function preserve(array $incoming, array $current): array
    {
        foreach (self::FIELDS as $field) $incoming[$field] = $current[$field] ?? null;
        return $incoming;
    }
}
