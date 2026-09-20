<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class FeoPlacementRouteNotificationService
{
    public const MAX_DISTANCE_METERS = 5000.0;
    public const ACTIVE_STATUSES = [
        WasteTripService::STATUS_PLANNED,
        WasteTripService::STATUS_SEARCHING,
        WasteTripService::STATUS_ASSIGNED,
        WasteTripService::STATUS_IN_PROGRESS,
    ];

    /** @return list<array<string,mixed>> */
    public function intents(PDO $tenant, PDO $central, int $companyId, string $blockType, array $businessIds): array
    {
        $businessIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): string => trim((string)$id), $businessIds
        ), static fn(string $id): bool => $id !== '')));
        if ($businessIds === []) return [];
        $selected = $this->requestRows($tenant, $businessIds);
        $active = $this->activeRows($tenant);
        if ($active === []) return [];

        $membershipIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['membership_id'] ?? 0), $active
        ))));
        $accounts = $this->managerAccounts($central, $companyId, $membershipIds);
        foreach ($active as &$row) {
            $row['account'] = $accounts[(int)($row['membership_id'] ?? 0)] ?? null;
        }
        unset($row);

        return $blockType === 'exclusion'
            ? $this->exclusionIntents($selected, $active)
            : $this->additionIntents($selected, $active);
    }

    public function intentForDelivery(
        PDO $tenant,
        PDO $central,
        int $companyId,
        int $blockId,
        string $kind,
        int $routeId,
        int $part
    ): ?array {
        if ($blockId <= 0 || $routeId <= 0 || $part <= 0) return null;
        if (!in_array($kind, ['same_point','nearby','active_exclusion'], true)) return null;
        $block = (new FeoPlacementService())->block($tenant, $blockId);
        if (!is_array($block) || (string)($block['status'] ?? '') !== 'applied') return null;
        $requestIds = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['zayavka_id'] ?? '')),
            (array)($block['requests'] ?? [])
        ), static fn(string $id): bool => $id !== ''));
        if ($requestIds === []) return null;
        foreach ($this->intents($tenant, $central, $companyId, (string)$block['block_type'], $requestIds) as $intent) {
            if ((string)$intent['kind'] === $kind
                && (int)$intent['route_id'] === $routeId
                && (int)$intent['part'] === $part) {
                return $intent;
            }
        }
        return null;
    }

    public static function isActiveStatus(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radius = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return $radius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    public static function massText(float $kg): string
    {
        $formatted = number_format(round(max(0.0, $kg), 3), 3, ',', ' ');
        $formatted = rtrim(rtrim($formatted, '0'), ',');
        return $formatted === '' ? '0' : $formatted;
    }

    /** @param array<string,array<string,mixed>> $selected @param list<array<string,mixed>> $active */
    public function additionIntents(array $selected, array $active): array
    {
        $sameByRequest = [];
        $sameGroups = [];
        foreach ($selected as $requestId => $new) {
            $coords = $new['coords'] ?? ['status'=>'invalid'];
            if (($coords['status'] ?? '') !== 'ok') continue;
            $newKey = FeoMapService::coordinateKey($coords);
            foreach ($active as $existing) {
                if ((string)($existing['zayavka_id'] ?? '') === (string)$requestId) continue;
                $existingCoords = $existing['coords'] ?? ['status'=>'invalid'];
                if (($existingCoords['status'] ?? '') !== 'ok') continue;
                if (FeoMapService::coordinateKey($existingCoords) !== $newKey) continue;
                $routeId = (int)$existing['route_id'];
                $sameByRequest[(string)$requestId] = true;
                $key = $requestId . '|' . $routeId;
                $candidate = ['new'=>$new, 'active'=>$existing];
                if (!isset($sameGroups[$routeId]['pairs'][$key])
                    || strnatcmp((string)$existing['zayavka_id'], (string)$sameGroups[$routeId]['pairs'][$key]['active']['zayavka_id']) < 0) {
                    $sameGroups[$routeId]['route'] = $existing;
                    $sameGroups[$routeId]['pairs'][$key] = $candidate;
                }
            }
        }

        $nearbyGroups = [];
        foreach ($selected as $requestId => $new) {
            if (isset($sameByRequest[(string)$requestId])) continue;
            $coords = $new['coords'] ?? ['status'=>'invalid'];
            if (($coords['status'] ?? '') !== 'ok') continue;
            foreach ($active as $existing) {
                if ((string)($existing['zayavka_id'] ?? '') === (string)$requestId) continue;
                $existingCoords = $existing['coords'] ?? ['status'=>'invalid'];
                if (($existingCoords['status'] ?? '') !== 'ok') continue;
                if (!self::withinRoughBox($coords, $existingCoords)) continue;
                $distance = self::distanceMeters(
                    (float)$coords['lat'], (float)$coords['lng'],
                    (float)$existingCoords['lat'], (float)$existingCoords['lng']
                );
                if ($distance > self::MAX_DISTANCE_METERS) continue;
                $routeId = (int)$existing['route_id'];
                $nearbyGroups[$routeId]['route'] = $existing;
                $nearbyGroups[$routeId]['requests'][(string)$requestId] = $new;
            }
        }

        ksort($sameGroups, SORT_NUMERIC);
        ksort($nearbyGroups, SORT_NUMERIC);
        $intents = [];
        foreach ($sameGroups as $group) {
            uasort($group['pairs'], static fn(array $a, array $b): int =>
                strnatcmp((string)$a['new']['zayavka_id'], (string)$b['new']['zayavka_id'])
            );
            array_push($intents, ...$this->samePointMessages($group['route'], array_values($group['pairs'])));
        }
        foreach ($nearbyGroups as $group) {
            uksort($group['requests'], 'strnatcmp');
            array_push($intents, ...$this->nearbyMessages($group['route'], array_values($group['requests'])));
        }
        return $intents;
    }

    /** @param array<string,array<string,mixed>> $selected @param list<array<string,mixed>> $active */
    public function exclusionIntents(array $selected, array $active): array
    {
        $groups = [];
        foreach ($active as $existing) {
            $id = (string)($existing['zayavka_id'] ?? '');
            if ($id === '' || !isset($selected[$id])) continue;
            $routeId = (int)$existing['route_id'];
            $groups[$routeId]['route'] = $existing;
            $groups[$routeId]['requests'][$id] = $selected[$id];
        }
        ksort($groups, SORT_NUMERIC);
        $intents = [];
        foreach ($groups as $group) {
            uksort($group['requests'], 'strnatcmp');
            array_push($intents, ...$this->exclusionMessages($group['route'], array_values($group['requests'])));
        }
        return $intents;
    }

    /** @return array<string,array<string,mixed>> */
    private function requestRows(PDO $pdo, array $businessIds): array
    {
        $rows = [];
        foreach (array_chunk($businessIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT id,zayavka_id,mass_netto,mno_d,mno_sh FROM feo_requests WHERE zayavka_id IN (" . $marks . ")");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string)$row['zayavka_id'];
                $rows[$id] = [
                    'feo_request_id'=>(int)$row['id'],
                    'zayavka_id'=>$id,
                    'mass_kg'=>WasteTripService::massKg($row['mass_netto'] ?? null),
                    'coords'=>FeoMapService::coordinates($row['mno_d'] ?? null, $row['mno_sh'] ?? null),
                ];
            }
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function activeRows(PDO $pdo): array
    {
        $marks = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $q = $pdo->prepare(
            "SELECT wt.id route_id,wt.business_id,wt.comment,wt.status,wt.responsible_user_id,
                    u.membership_id,u.full_name responsible_name,
                    fr.id feo_request_id,fr.zayavka_id,fr.mass_netto,fr.mno_d,fr.mno_sh
             FROM waste_trips wt
             JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id
             JOIN feo_requests fr ON fr.id=wtr.feo_request_id
             JOIN users u ON u.id=wt.responsible_user_id
             WHERE wt.deleted_at IS NULL AND wt.status IN (" . $marks . ")
             ORDER BY wt.id,fr.zayavka_id"
        );
        $q->execute(self::ACTIVE_STATUSES);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['route_id'] = (int)$row['route_id'];
            $row['business_id'] = (int)$row['business_id'];
            $row['membership_id'] = (int)($row['membership_id'] ?? 0);
            $row['mass_kg'] = WasteTripService::massKg($row['mass_netto'] ?? null);
            $row['coords'] = FeoMapService::coordinates($row['mno_d'] ?? null, $row['mno_sh'] ?? null);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function managerAccounts(PDO $pdo, int $companyId, array $membershipIds): array
    {
        if ($membershipIds === []) return [];
        $hasMax = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'"
        )->fetchColumn() === 1;
        $selectMax = $hasMax ? 'a.max_user_id' : 'NULL AS max_user_id';
        $out = [];
        foreach (array_chunk($membershipIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare(
                "SELECT m.id membership_id,a.id account_id,a.full_name," . $selectMax . "
                 FROM company_memberships m
                 JOIN user_accounts a ON a.id=m.account_id
                 WHERE m.company_id=? AND m.id IN (" . $marks . ")"
            );
            $q->execute(array_merge([$companyId], $chunk));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['membership_id']] = $row;
        }
        return $out;
    }

    private static function withinRoughBox(array $a, array $b): bool
    {
        $latA = (float)$a['lat'];
        $latB = (float)$b['lat'];
        if (abs($latA - $latB) > 0.05) return false;
        $cos = max(0.05, cos(deg2rad(($latA + $latB) / 2)));
        return abs((float)$a['lng'] - (float)$b['lng']) <= 0.05 / $cos;
    }

    /** @return list<array<string,mixed>> */
    private function samePointMessages(array $route, array $pairs): array
    {
        $error = $this->managerError($route);
        if ($error !== null) return [$this->failedIntent('same_point', $route, $error)];
        $header = $this->mention($route) . ', заявки из нового блока находятся в той же точке, что активные заявки рейса ' . $this->routeLabel($route) . '.';
        $lines = [];
        foreach ($pairs as $pair) {
            $lines[] = 'Новая ' . self::md((string)$pair['new']['zayavka_id'])
                . ' — ' . self::massText((float)$pair['new']['mass_kg']) . ' кг; активная '
                . self::md((string)$pair['active']['zayavka_id']) . '.';
        }
        return $this->chunkMessages('same_point', $route, $header, $lines, 'Эти заявки необходимо также включить в рейс.');
    }

    /** @return list<array<string,mixed>> */
    private function nearbyMessages(array $route, array $requests): array
    {
        $error = $this->managerError($route);
        if ($error !== null) return [$this->failedIntent('nearby', $route, $error)];
        $header = $this->mention($route) . ', добавленные заявки находятся вблизи (не более 5 км) заявок рейса ' . $this->routeLabel($route) . '.';
        $lines = array_map(static fn(array $row): string =>
            self::md((string)$row['zayavka_id']) . ' — ' . self::massText((float)$row['mass_kg']) . ' кг', $requests);
        return $this->chunkMessages('nearby', $route, $header, $lines, 'Просим проверить возможность добавления в рейс.');
    }

    /** @return list<array<string,mixed>> */
    private function exclusionMessages(array $route, array $requests): array
    {
        $error = $this->managerError($route);
        if ($error !== null) return [$this->failedIntent('active_exclusion', $route, $error)];
        $count = count($requests);
        $mass = array_sum(array_map(static fn(array $row): float => (float)$row['mass_kg'], $requests));
        $header = $this->mention($route) . ', с карты сняты активные заявки рейса ' . $this->routeLabel($route)
            . '. Всего: ' . $count . ', масса: ' . self::massText($mass) . ' кг.';
        $lines = array_map(static fn(array $row): string =>
            self::md((string)$row['zayavka_id']) . ' — ' . self::massText((float)$row['mass_kg']) . ' кг', $requests);
        return $this->chunkMessages('active_exclusion', $route, $header, $lines, 'Проверьте состав рейса.');
    }

    private function managerError(array $route): ?string
    {
        $name = trim((string)($route['account']['full_name'] ?? $route['responsible_name'] ?? ''));
        if ((int)($route['membership_id'] ?? 0) <= 0) {
            return 'Не удалось связать ответственного менеджера ' . ($name ?: 'рейса') . ' с центральной учётной записью.';
        }
        if (!is_array($route['account'] ?? null)) {
            return 'Не найдена центральная учётная запись ответственного менеджера ' . ($name ?: 'рейса') . '.';
        }
        $maxId = trim((string)($route['account']['max_user_id'] ?? ''));
        if ($maxId === '') return 'Не заполнен MAX ID ответственного менеджера ' . ($name ?: 'рейса') . '.';
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $maxId) !== 1
            || (strlen($maxId) === 19 && strcmp($maxId, '9223372036854775807') > 0)) {
            return 'Некорректный MAX ID ответственного менеджера ' . ($name ?: 'рейса') . '.';
        }
        return null;
    }

    private function mention(array $route): string
    {
        $account = $route['account'];
        return '[' . self::md((string)$account['full_name']) . '](max://user/' . (string)$account['max_user_id'] . ')';
    }

    private function routeLabel(array $route): string
    {
        $label = '#' . (int)$route['business_id'];
        $comment = trim((string)($route['comment'] ?? ''));
        if ($comment !== '') $label .= ' ' . $comment;
        return self::md($label);
    }

    /** @return array<string,mixed> */
    private function failedIntent(string $kind, array $route, string $error): array
    {
        return [
            'kind'=>$kind, 'route_id'=>(int)$route['route_id'], 'part'=>1,
            'text'=>'', 'format'=>'markdown', 'error'=>$error,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function chunkMessages(string $kind, array $route, string $header, array $lines, string $footer): array
    {
        $chunks = [];
        $current = [];
        foreach ($lines as $line) {
            $candidate = [...$current, $line];
            $text = $header . "\n\n" . implode("\n", $candidate) . "\n\n" . $footer;
            if ($current !== [] && mb_strlen($text, 'UTF-8') > 3920) {
                $chunks[] = $current;
                $current = [$line];
            } else {
                $current = $candidate;
            }
        }
        if ($current !== []) $chunks[] = $current;
        $total = count($chunks);
        $out = [];
        foreach ($chunks as $index => $chunk) {
            $part = $index + 1;
            $partLabel = $total > 1 ? 'Часть ' . $part . '/' . $total . ".\n" : '';
            $text = $partLabel . $header . "\n\n" . implode("\n", $chunk) . "\n\n" . $footer;
            if (mb_strlen($text, 'UTF-8') > 4000) throw new RuntimeException('Адресное MAX-сообщение превышает 4000 символов.');
            $out[] = [
                'kind'=>$kind, 'route_id'=>(int)$route['route_id'], 'part'=>$part,
                'text'=>$text, 'format'=>'markdown', 'error'=>null,
            ];
        }
        return $out;
    }

    private static function md(string $value): string
    {
        $value = str_replace(chr(96), '\\' . chr(96), $value);
        return preg_replace('/([\\\\*_{}\[\]()#+\-.!|>])/u', '\\\\$1', $value) ?? $value;
    }
}
