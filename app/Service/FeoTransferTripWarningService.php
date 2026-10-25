<?php

namespace App\Service;

use PDO;

require_once __DIR__ . '/WasteTripService.php';
require_once __DIR__ . '/RegistryManagerPresentation.php';

/** One MAX warning for affected active trips, split only when MAX's limit requires it. */
final class FeoTransferTripWarningService
{
    private const ACTIVE_STATUSES = [
        WasteTripService::STATUS_PLANNED,
        WasteTripService::STATUS_SEARCHING,
        WasteTripService::STATUS_ASSIGNED,
        WasteTripService::STATUS_IN_PROGRESS,
    ];
    private const HEADER = "ПЕРЕМЕЩЕННЫЕ ЗАЯВКИ\nнаходятся в следующих рейсах, будьте внимательны";

    /** @return list<array{part:int,text:string,format:string}> */
    public function intents(PDO $pdo, array $requestNumbers): array
    {
        $numbers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value), $requestNumbers
        ), static fn(string $value): bool => preg_match('/^[0-9]{6}$/D', $value) === 1)));
        if ($numbers === []) return [];
        // Waste trips are an optional module during a staged tenant rollout.
        // Missing module tables mean there can be no affected trips; other SQL errors propagate.
        $schema = $pdo->query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('waste_trips','waste_trip_requests')");
        if ((int)$schema->fetchColumn() !== 2) return [];

        $routes = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $states = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
            $query = $pdo->prepare(
                "SELECT wt.id route_id, wt.business_id, wt.comment, u.full_name responsible_name, fr.zayavka_id\n"
                . "FROM feo_requests fr\n"
                . "JOIN waste_trip_requests wtr ON wtr.feo_request_id=fr.id AND wtr.removed_at IS NULL\n"
                . "JOIN waste_trips wt ON wt.id=wtr.waste_trip_id AND wt.deleted_at IS NULL\n"
                . "JOIN users u ON u.id=wt.responsible_user_id\n"
                . "WHERE fr.zayavka_id IN ($marks) AND wt.status IN ($states)\n"
                . "ORDER BY wt.business_id, wt.id, fr.zayavka_id"
            );
            $query->execute([...$chunk, ...self::ACTIVE_STATUSES]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['route_id'];
                if (!isset($routes[$id])) {
                    $routes[$id] = [
                        'business_id'=>(int)$row['business_id'],
                        'route_id'=>$id,
                        'comment'=>(string)($row['comment'] ?? ''),
                        'responsible_name'=>(string)$row['responsible_name'],
                        'requests'=>[],
                    ];
                }
                $routes[$id]['requests'][(string)$row['zayavka_id']] = true;
            }
        }
        if ($routes === []) return [];

        $routes = array_values($routes);
        usort($routes, static fn(array $a, array $b): int =>
            [$a['business_id'],$a['route_id']] <=> [$b['business_id'],$b['route_id']]);
        $blocks = [];
        foreach ($routes as $route) {
            $numbers = array_keys($route['requests']);
            sort($numbers, SORT_NATURAL);
            $label = '#' . $route['business_id'];
            $comment = trim(preg_replace('/\s+/u', ' ', $route['comment']) ?? $route['comment']);
            if ($comment !== '') $label .= ' ' . $comment;
            $manager = RegistryManagerPresentation::name($route['responsible_name']);
            $title = '**' . self::markdown($label) . '** — ' . self::markdown($manager);
            $prefix = $title . "\nЗаявки: **";
            $current = [];
            foreach ($numbers as $number) {
                $candidate = [...$current, $number];
                if ($current !== [] && mb_strlen($prefix . implode(', ', $candidate) . '**', 'UTF-8') > 3400) {
                    $blocks[] = $prefix . implode(', ', $current) . '**';
                    $current = [$number];
                } else {
                    $current = $candidate;
                }
            }
            if ($current !== []) $blocks[] = $prefix . implode(', ', $current) . '**';
        }

        $parts = [];
        $current = [];
        foreach ($blocks as $block) {
            $candidate = [...$current, $block];
            if ($current !== [] && mb_strlen(self::HEADER . "\n\n" . implode("\n\n", $candidate), 'UTF-8') > 3800) {
                $parts[] = $current;
                $current = [$block];
            } else {
                $current = $candidate;
            }
        }
        if ($current !== []) $parts[] = $current;
        $out = [];
        foreach ($parts as $index => $part) {
            $partLabel = count($parts) > 1 ? 'Часть ' . ($index + 1) . '/' . count($parts) . "\n" : '';
            $out[] = [
                'part'=>$index + 1,
                'text'=>$partLabel . self::HEADER . "\n\n" . implode("\n\n", $part),
                'format'=>'markdown',
            ];
        }
        return $out;
    }

    private static function markdown(string $value): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>~])/u', '\\\\$1', $value) ?? $value;
    }
}
