<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Legacy include/price.php: weight shares, whole rubles, remainder to the heaviest request. */
final class WasteTripLegacyPriceService
{
    public static function fetchPrices(PDO $legacy): array
    {
        $blocks = $legacy->query("SELECT id,zayavki_ids,cost FROM status_blocks WHERE status_type='marshrut' AND cost IS NOT NULL AND cost>0 AND zayavki_ids<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($blocks as $block) foreach (self::ids((string)$block['zayavki_ids']) as $id) $ids[$id] = true;
        $weights = [];
        foreach (array_chunk(array_keys($ids), 800) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $legacy->prepare("SELECT zayavka_id,mass_netto FROM feo WHERE zayavka_id IN ({$marks})");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $weights[(string)$row['zayavka_id']] = (float)$row['mass_netto'] * 1000;
        }
        return self::calculate($blocks, $weights);
    }

    public static function calculate(array $blocks, array $weights): array
    {
        $prices = [];
        foreach ($blocks as $block) {
            $amount = LinearRouteService::parseAmount((string)$block['cost']);
            if ($amount === null) throw new RuntimeException('Некорректная стоимость блока старой БД.');
            $valid = [];
            $maxIndex = 0;
            $maxWeight = 0;
            foreach (self::ids((string)$block['zayavki_ids']) as $id) {
                $weight = (float)($weights[$id] ?? 0);
                if ($weight <= 0) continue;
                if ($weight > $maxWeight) { $maxWeight = $weight; $maxIndex = count($valid); }
                $valid[] = ['id'=>$id, 'weight'=>$weight];
            }
            $total = array_sum(array_column($valid, 'weight'));
            if ($total <= 0) continue;
            $sumRubles = 0;
            foreach ($valid as $index => $request) {
                if ($index === $maxIndex) continue;
                $rubles = (int)floor(($request['weight'] / $total) * (float)$amount);
                $prices[$request['id']] = ($prices[$request['id']] ?? 0) + $rubles * 100;
                $sumRubles += $rubles;
            }
            $id = $valid[$maxIndex]['id'];
            $prices[$id] = ($prices[$id] ?? 0) + (int)round(((float)$amount - $sumRubles) * 100);
        }
        return $prices;
    }

    public static function flightTotal(array $ids, array $prices): ?string
    {
        if ($ids === []) return null;
        $sum = 0;
        foreach ($ids as $id) {
            if (!array_key_exists($id, $prices)) return null;
            $sum += $prices[$id];
        }
        return number_format($sum / 100, 2, '.', '');
    }

    private static function ids(string $raw): array
    {
        // Retain source order (including repeats), exactly as legacy price.php does.
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
