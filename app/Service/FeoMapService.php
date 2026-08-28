<?php

namespace App\Service;

final class FeoMapService
{
    public function __construct(private readonly FeoUtilizerService $utilizers = new FeoUtilizerService())
    {
    }

    public function payload(\PDO $pdo): array
    {
        $rows = $this->availableRows($pdo);
        $classifications = $this->utilizers->classifyRequests($pdo, $rows);
        return self::buildPayload($rows, $classifications);
    }

    public function availableRows(\PDO $pdo): array
    {
        $sql = "SELECT
                    c.zayavka_id,
                    c.source_block_id,
                    c.applied_at,
                    r.mass_netto,
                    r.naim_oo_gruzootpravitel,
                    r.mno_adres_pogruzki,
                    r.gruzopoluchatel,
                    r.adres_dostavki_otkhodov,
                    r.nomer_opasnogo_gr,
                    r.mno_sh,
                    r.mno_d
                FROM feo_placement_current c
                INNER JOIN feo_requests r ON r.zayavka_id=c.zayavka_id
                ORDER BY c.applied_at DESC,c.zayavka_id ASC";
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function buildPayload(array $rows, array $classifications): array
    {
        $groups = [];
        $diagnostics = [
            'available_request_count'=>count($rows),
            'marker_request_count'=>0,
            'marker_count'=>0,
            'missing_coordinates'=>[],
            'invalid_coordinates'=>[],
            'unmatched_utilizer'=>[],
            'inactive_utilizer'=>[],
        ];

        foreach ($rows as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id === '') continue;
            $coords = self::coordinates($row['mno_sh'] ?? null, $row['mno_d'] ?? null);
            if ($coords['status'] === 'missing') {
                $diagnostics['missing_coordinates'][] = $id;
                continue;
            }
            if ($coords['status'] !== 'ok') {
                $diagnostics['invalid_coordinates'][] = $id;
                continue;
            }

            $classification = $classifications[$id] ?? [
                'status'=>'unmatched','source'=>'none','utilizer_id'=>null,'utilizer'=>null,'rule_id'=>null,
            ];
            $classStatus = (string)($classification['status'] ?? 'unmatched');
            $utilizer = is_array($classification['utilizer'] ?? null) ? $classification['utilizer'] : null;
            $utilizerId = $classification['utilizer_id'] ?? null;
            if ($classStatus === 'unmatched') $diagnostics['unmatched_utilizer'][] = $id;
            if ($classStatus === 'inactive') $diagnostics['inactive_utilizer'][] = $id;

            $state = 'available';
            $utilizerKey = $utilizerId ? 'utilizer:' . (int)$utilizerId : $classStatus;
            $coordKey = sprintf('%.7F|%.7F', $coords['lat'], $coords['lng']);
            $key = $coordKey . '|' . $state . '|' . $utilizerKey;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'=>$key,
                    'canonical_lat'=>$coords['lat'],
                    'canonical_lng'=>$coords['lng'],
                    'state'=>$state,
                    'classification_status'=>$classStatus,
                    'utilizer_id'=>$utilizerId ? (int)$utilizerId : null,
                    'utilizer'=>$utilizer ? [
                        'id'=>(int)$utilizer['id'],
                        'full_name'=>(string)($utilizer['full_name'] ?? ''),
                        'short_name'=>(string)($utilizer['short_name'] ?? ''),
                        'address'=>(string)($utilizer['address'] ?? ''),
                        'adr'=>(int)($utilizer['adr'] ?? 0) === 1,
                        'color_hex'=>(string)($utilizer['color_hex'] ?? '#64748B'),
                        'is_active'=>(int)($utilizer['is_active'] ?? 0) === 1,
                        'sort_order'=>(int)($utilizer['sort_order'] ?? 100),
                    ] : null,
                    'request_count'=>0,
                    'total_mass_kg'=>0.0,
                    'adr'=>$utilizer ? ((int)($utilizer['adr'] ?? 0) === 1) : self::requestAdr($row),
                    'requests'=>[],
                    'overlap_index'=>0,
                    'overlap_count'=>1,
                ];
            }

            $massKg = self::massKg($row['mass_netto'] ?? null);
            $groups[$key]['request_count']++;
            $groups[$key]['total_mass_kg'] += $massKg;
            $groups[$key]['adr'] = (bool)$groups[$key]['adr'] || self::requestAdr($row);
            $groups[$key]['requests'][] = [
                'zayavka_id'=>$id,
                'mass_kg'=>$massKg,
                'sender'=>(string)($row['naim_oo_gruzootpravitel'] ?? ''),
                'loading_address'=>(string)($row['mno_adres_pogruzki'] ?? ''),
                'recipient'=>(string)($row['gruzopoluchatel'] ?? ''),
                'delivery_address'=>(string)($row['adres_dostavki_otkhodov'] ?? ''),
                'source_block_id'=>isset($row['source_block_id']) ? (int)$row['source_block_id'] : null,
                'classification_source'=>(string)($classification['source'] ?? 'none'),
                'rule_id'=>isset($classification['rule_id']) ? (int)$classification['rule_id'] : null,
            ];
            $diagnostics['marker_request_count']++;
        }

        $markers = array_values($groups);
        usort($markers, static function (array $a, array $b): int {
            $as = (int)($a['utilizer']['sort_order'] ?? 1000000);
            $bs = (int)($b['utilizer']['sort_order'] ?? 1000000);
            return [$as,(string)($a['utilizer']['short_name'] ?? ''),$a['key']] <=> [$bs,(string)($b['utilizer']['short_name'] ?? ''),$b['key']];
        });

        $sameCoordinates = [];
        foreach ($markers as $i => $marker) {
            $coordKey = sprintf('%.7F|%.7F', (float)$marker['canonical_lat'], (float)$marker['canonical_lng']);
            $sameCoordinates[$coordKey][] = $i;
        }
        foreach ($sameCoordinates as $indexes) {
            $count = count($indexes);
            foreach ($indexes as $overlapIndex => $markerIndex) {
                $markers[$markerIndex]['overlap_index'] = $overlapIndex;
                $markers[$markerIndex]['overlap_count'] = $count;
            }
        }

        $layers = self::layers($markers);
        $diagnostics['marker_count'] = count($markers);
        foreach (['missing_coordinates','invalid_coordinates','unmatched_utilizer','inactive_utilizer'] as $key) {
            $diagnostics[$key] = array_values(array_unique($diagnostics[$key]));
            $diagnostics[$key . '_count'] = count($diagnostics[$key]);
        }
        return ['markers'=>$markers,'layers'=>$layers,'diagnostics'=>$diagnostics];
    }

    public static function coordinates(mixed $latRaw, mixed $lngRaw): array
    {
        $latText = trim((string)($latRaw ?? ''));
        $lngText = trim((string)($lngRaw ?? ''));
        if ($latText === '' && $lngText === '') return ['status'=>'missing','lat'=>null,'lng'=>null];
        if ($latText === '' || $lngText === '') return ['status'=>'invalid','lat'=>null,'lng'=>null];
        $latText = str_replace(',', '.', $latText);
        $lngText = str_replace(',', '.', $lngText);
        if (!is_numeric($latText) || !is_numeric($lngText)) return ['status'=>'invalid','lat'=>null,'lng'=>null];
        $lat = (float)$latText;
        $lng = (float)$lngText;
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return ['status'=>'invalid','lat'=>null,'lng'=>null];
        }
        return ['status'=>'ok','lat'=>$lat,'lng'=>$lng];
    }

    private static function layers(array $markers): array
    {
        $layers = [
            'available'=>[
                'key'=>'available','label'=>'Доступные заявки','color_hex'=>'#2563EB','request_count'=>0,'marker_count'=>0,
                'kind'=>'state','is_active'=>true,'sort_order'=>0,
            ],
        ];
        foreach ($markers as $marker) {
            $layers['available']['request_count'] += (int)$marker['request_count'];
            $layers['available']['marker_count']++;
            $status = (string)$marker['classification_status'];
            $utilizer = $marker['utilizer'] ?? null;
            if ($utilizer && $status === 'matched') {
                $key = 'utilizer:' . (int)$utilizer['id'];
                if (!isset($layers[$key])) {
                    $layers[$key] = [
                        'key'=>$key,'label'=>(string)$utilizer['short_name'],'color_hex'=>(string)$utilizer['color_hex'],
                        'request_count'=>0,'marker_count'=>0,'kind'=>'utilizer','is_active'=>true,
                        'sort_order'=>(int)$utilizer['sort_order'],'utilizer_id'=>(int)$utilizer['id'],
                    ];
                }
            } elseif ($status === 'inactive' && $utilizer) {
                $key = 'inactive:' . (int)$utilizer['id'];
                if (!isset($layers[$key])) {
                    $layers[$key] = [
                        'key'=>$key,'label'=>'Отключён: ' . (string)$utilizer['short_name'],'color_hex'=>'#94A3B8',
                        'request_count'=>0,'marker_count'=>0,'kind'=>'diagnostic','is_active'=>false,
                        'sort_order'=>900000 + (int)$utilizer['sort_order'],'utilizer_id'=>(int)$utilizer['id'],
                    ];
                }
            } else {
                $key = 'unmatched';
                if (!isset($layers[$key])) {
                    $layers[$key] = [
                        'key'=>$key,'label'=>'Без утилизатора','color_hex'=>'#64748B','request_count'=>0,'marker_count'=>0,
                        'kind'=>'diagnostic','is_active'=>true,'sort_order'=>800000,
                    ];
                }
            }
            $layers[$key]['request_count'] += (int)$marker['request_count'];
            $layers[$key]['marker_count']++;
        }
        $out = array_values($layers);
        usort($out, static fn(array $a, array $b): int => [(int)$a['sort_order'],(string)$a['label']] <=> [(int)$b['sort_order'],(string)$b['label']]);
        return $out;
    }

    private static function massKg(mixed $massNetto): float
    {
        $raw = str_replace(',', '.', trim((string)($massNetto ?? '')));
        if ($raw === '' || !is_numeric($raw)) return 0.0;
        return round(max(0.0, (float)$raw * 1000.0), 3);
    }

    private static function requestAdr(array $row): bool
    {
        $value = trim((string)($row['nomer_opasnogo_gr'] ?? ''));
        if ($value === '' || $value === '-') return false;
        $normalized = FeoUtilizerService::normalizeText($value);
        return $normalized !== '' && !in_array($normalized, ['нет','no','0'], true);
    }
}
