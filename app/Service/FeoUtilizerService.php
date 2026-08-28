<?php

namespace App\Service;

final class FeoUtilizerService
{
    public function listUtilizers(\PDO $pdo, bool $includeInactive = true): array
    {
        $sql = 'SELECT * FROM feo_utilizers';
        if (!$includeInactive) $sql .= ' WHERE is_active=1';
        $sql .= ' ORDER BY sort_order ASC, short_name ASC, id ASC';
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function listRules(\PDO $pdo): array
    {
        $sql = "SELECT r.*,u.full_name utilizer_full_name,u.short_name utilizer_short_name,u.is_active utilizer_is_active
                  FROM feo_utilizer_match_rules r
                  JOIN feo_utilizers u ON u.id=r.utilizer_id
                 ORDER BY r.priority ASC,
                          CASE r.match_type WHEN 'exact' THEN 0 ELSE 1 END ASC,
                          r.id ASC";
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function listOverrides(\PDO $pdo, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $sql = "SELECT o.zayavka_id,o.utilizer_id,o.created_at,o.updated_at,u.short_name utilizer_short_name,u.full_name utilizer_full_name,u.is_active utilizer_is_active
                  FROM feo_request_utilizer_overrides o
                  JOIN feo_utilizers u ON u.id=o.utilizer_id
                 ORDER BY o.updated_at DESC,o.zayavka_id ASC
                 LIMIT {$limit}";
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function createUtilizer(\PDO $pdo, array $input, int $userId): int
    {
        $data = $this->validateUtilizer($input);
        $q = $pdo->prepare('INSERT INTO feo_utilizers
            (full_name,short_name,address,latitude,longitude,fkko_classification,adr,color_hex,is_active,sort_order,created_by_user_id,updated_by_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([
            $data['full_name'],$data['short_name'],$data['address'],$data['latitude'],$data['longitude'],
            $data['fkko_classification'],$data['adr'],$data['color_hex'],$data['is_active'],$data['sort_order'],
            $userId ?: null,$userId ?: null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public function updateUtilizer(\PDO $pdo, int $id, array $input, int $userId): void
    {
        if ($id <= 0) throw new \RuntimeException('Некорректный утилизатор.');
        $data = $this->validateUtilizer($input);
        $q = $pdo->prepare('UPDATE feo_utilizers SET full_name=?,short_name=?,address=?,latitude=?,longitude=?,fkko_classification=?,adr=?,color_hex=?,is_active=?,sort_order=?,updated_by_user_id=? WHERE id=?');
        $q->execute([
            $data['full_name'],$data['short_name'],$data['address'],$data['latitude'],$data['longitude'],
            $data['fkko_classification'],$data['adr'],$data['color_hex'],$data['is_active'],$data['sort_order'],
            $userId ?: null,$id,
        ]);
        if ($q->rowCount() === 0 && !$this->utilizerExists($pdo, $id)) throw new \RuntimeException('Утилизатор не найден.');
    }

    public function deleteUtilizer(\PDO $pdo, int $id): void
    {
        if ($id <= 0) throw new \RuntimeException('Некорректный утилизатор.');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM feo_utilizers WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            if (!$q->fetchColumn()) throw new \RuntimeException('Утилизатор не найден.');

            $q = $pdo->prepare('SELECT COUNT(*) FROM feo_utilizer_match_rules WHERE utilizer_id=?');
            $q->execute([$id]);
            $rules = (int)$q->fetchColumn();
            $q = $pdo->prepare('SELECT COUNT(*) FROM feo_request_utilizer_overrides WHERE utilizer_id=?');
            $q->execute([$id]);
            $overrides = (int)$q->fetchColumn();
            if ($rules > 0 || $overrides > 0) {
                throw new \RuntimeException(sprintf('Удаление запрещено: у утилизатора есть правила (%d) или ручные назначения (%d). Сначала удалите связи либо отключите утилизатора.', $rules, $overrides));
            }
            $q = $pdo->prepare('DELETE FROM feo_utilizers WHERE id=?');
            $q->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function createRule(\PDO $pdo, array $input, int $userId): int
    {
        $utilizerId = (int)($input['utilizer_id'] ?? 0);
        if (!$this->utilizerExists($pdo, $utilizerId)) throw new \RuntimeException('Выберите существующего утилизатора.');
        $source = (string)($input['source_field'] ?? '');
        if (!in_array($source, ['recipient','delivery_address'], true)) throw new \RuntimeException('Некорректный источник правила.');
        $type = (string)($input['match_type'] ?? '');
        if (!in_array($type, ['exact','contains'], true)) throw new \RuntimeException('Некорректный тип сопоставления.');
        $pattern = trim((string)($input['pattern'] ?? ''));
        $normalized = self::normalizeText($pattern);
        if ($normalized === '' || self::textLength($pattern) > 500) throw new \RuntimeException('Укажите корректный шаблон правила.');
        if ($type === 'contains' && self::textLength($normalized) < 3) throw new \RuntimeException('Для правила «содержит» нужно минимум 3 символа.');
        $priority = max(0, min(100000, (int)($input['priority'] ?? 100)));
        $active = $this->boolInput($input['is_active'] ?? 0);

        try {
            $q = $pdo->prepare('INSERT INTO feo_utilizer_match_rules
                (utilizer_id,source_field,match_type,pattern,normalized_pattern,priority,is_active,created_by_user_id,updated_by_user_id)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute([$utilizerId,$source,$type,$pattern,$normalized,$priority,$active,$userId ?: null,$userId ?: null]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') throw new \RuntimeException('Такое каноническое правило уже существует.');
            throw $e;
        }
        return (int)$pdo->lastInsertId();
    }

    public function deleteRule(\PDO $pdo, int $id): void
    {
        if ($id <= 0) throw new \RuntimeException('Некорректное правило.');
        $q = $pdo->prepare('DELETE FROM feo_utilizer_match_rules WHERE id=?');
        $q->execute([$id]);
        if ($q->rowCount() === 0) throw new \RuntimeException('Правило не найдено.');
    }

    public function setOverride(\PDO $pdo, string $zayavkaId, int $utilizerId, int $userId): void
    {
        $zayavkaId = trim($zayavkaId);
        if (!preg_match('/^\d{6}$/D', $zayavkaId)) throw new \RuntimeException('ID заявки должен состоять ровно из 6 цифр.');
        if (!$this->utilizerExists($pdo, $utilizerId)) throw new \RuntimeException('Выберите существующего утилизатора.');
        $q = $pdo->prepare('SELECT 1 FROM feo_requests WHERE zayavka_id=?');
        $q->execute([$zayavkaId]);
        if (!$q->fetchColumn()) throw new \RuntimeException('Заявка ФЭО не найдена.');
        $q = $pdo->prepare('INSERT INTO feo_request_utilizer_overrides
            (zayavka_id,utilizer_id,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE utilizer_id=VALUES(utilizer_id),updated_by_user_id=VALUES(updated_by_user_id),updated_at=CURRENT_TIMESTAMP');
        $q->execute([$zayavkaId,$utilizerId,$userId ?: null,$userId ?: null]);
    }

    public function deleteOverride(\PDO $pdo, string $zayavkaId): void
    {
        $q = $pdo->prepare('DELETE FROM feo_request_utilizer_overrides WHERE zayavka_id=?');
        $q->execute([trim($zayavkaId)]);
    }

    public function classifyRequests(\PDO $pdo, array $requests): array
    {
        if ($requests === []) return [];
        $utilizers = [];
        foreach ($this->listUtilizers($pdo, true) as $row) $utilizers[(int)$row['id']] = $row;

        $rules = $this->listRules($pdo);
        $rules = array_values(array_filter($rules, static fn(array $r): bool => (int)($r['is_active'] ?? 0) === 1));

        $ids = [];
        foreach ($requests as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        $overrides = $this->overrideMap($pdo, $ids);
        $out = [];
        foreach ($requests as $row) {
            $id = (string)($row['zayavka_id'] ?? '');
            $overrideId = (int)($overrides[$id] ?? 0);
            if ($overrideId > 0 && isset($utilizers[$overrideId])) {
                $out[$id] = $this->classification('override', $utilizers[$overrideId], null);
                continue;
            }

            $recipient = self::normalizeText((string)($row['gruzopoluchatel'] ?? ''));
            $address = self::normalizeText((string)($row['adres_dostavki_otkhodov'] ?? ''));
            $matched = null;
            foreach ($rules as $rule) {
                $value = ($rule['source_field'] ?? '') === 'recipient' ? $recipient : $address;
                $needle = (string)($rule['normalized_pattern'] ?? '');
                if ($value === '' || $needle === '') continue;
                $ok = ($rule['match_type'] ?? '') === 'exact' ? hash_equals($needle, $value) : str_contains($value, $needle);
                if (!$ok) continue;
                $uid = (int)$rule['utilizer_id'];
                if (!isset($utilizers[$uid])) continue;
                $matched = $this->classification('rule', $utilizers[$uid], $rule);
                break;
            }
            $out[$id] = $matched ?? [
                'status'=>'unmatched','source'=>'none','utilizer_id'=>null,'utilizer'=>null,'rule_id'=>null,
            ];
        }
        return $out;
    }

    public static function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
            $value = strtr($value, [
                'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й',
                'К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф',
                'Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я',
            ]);
        }
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public static function ruleMatches(array $rule, array $request): bool
    {
        $source = ($rule['source_field'] ?? '') === 'recipient'
            ? (string)($request['gruzopoluchatel'] ?? '')
            : (string)($request['adres_dostavki_otkhodov'] ?? '');
        $value = self::normalizeText($source);
        $needle = self::normalizeText((string)($rule['pattern'] ?? $rule['normalized_pattern'] ?? ''));
        if ($value === '' || $needle === '') return false;
        return ($rule['match_type'] ?? '') === 'exact' ? $value === $needle : str_contains($value, $needle);
    }


    private static function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? count($chars) : strlen($value);
    }

    private function classification(string $source, array $utilizer, ?array $rule): array
    {
        return [
            'status'=>(int)($utilizer['is_active'] ?? 0) === 1 ? 'matched' : 'inactive',
            'source'=>$source,
            'utilizer_id'=>(int)$utilizer['id'],
            'utilizer'=>$utilizer,
            'rule_id'=>$rule ? (int)$rule['id'] : null,
        ];
    }

    private function overrideMap(\PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
        $out = [];
        foreach (array_chunk($ids, 800) as $chunk) {
            if ($chunk === []) continue;
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT zayavka_id,utilizer_id FROM feo_request_utilizer_overrides WHERE zayavka_id IN ({$marks})");
            $q->execute($chunk);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) $out[(string)$row['zayavka_id']] = (int)$row['utilizer_id'];
        }
        return $out;
    }

    private function validateUtilizer(array $input): array
    {
        $full = trim((string)($input['full_name'] ?? ''));
        $short = trim((string)($input['short_name'] ?? ''));
        if ($full === '' || self::textLength($full) > 255) throw new \RuntimeException('Укажите полное название утилизатора.');
        if ($short === '' || self::textLength($short) > 80) throw new \RuntimeException('Укажите короткое название для карты.');
        $address = trim((string)($input['address'] ?? ''));
        $fkko = trim((string)($input['fkko_classification'] ?? ''));
        if (self::textLength($address) > 500 || self::textLength($fkko) > 500) throw new \RuntimeException('Адрес или классификация слишком длинные.');
        [$lat,$lng] = $this->coordinates($input['latitude'] ?? null, $input['longitude'] ?? null);
        $color = strtoupper(trim((string)($input['color_hex'] ?? '#2563EB')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) throw new \RuntimeException('Цвет должен быть в формате #RRGGBB.');
        return [
            'full_name'=>$full,'short_name'=>$short,'address'=>$address !== '' ? $address : null,
            'latitude'=>$lat,'longitude'=>$lng,'fkko_classification'=>$fkko !== '' ? $fkko : null,
            'adr'=>$this->boolInput($input['adr'] ?? 0),'color_hex'=>$color,
            'is_active'=>$this->boolInput($input['is_active'] ?? 0),'sort_order'=>max(-100000, min(100000, (int)($input['sort_order'] ?? 100))),
        ];
    }

    private function coordinates(mixed $latitude, mixed $longitude): array
    {
        $latRaw = trim((string)($latitude ?? ''));
        $lngRaw = trim((string)($longitude ?? ''));
        if ($latRaw === '' && $lngRaw === '') return [null,null];
        if ($latRaw === '' || $lngRaw === '' || !is_numeric(str_replace(',', '.', $latRaw)) || !is_numeric(str_replace(',', '.', $lngRaw))) {
            throw new \RuntimeException('Координаты должны быть заполнены парой чисел.');
        }
        $lat = (float)str_replace(',', '.', $latRaw);
        $lng = (float)str_replace(',', '.', $lngRaw);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new \RuntimeException('Координаты выходят за допустимый диапазон.');
        return [number_format($lat, 7, '.', ''), number_format($lng, 7, '.', '')];
    }

    private function utilizerExists(\PDO $pdo, int $id): bool
    {
        if ($id <= 0) return false;
        $q = $pdo->prepare('SELECT 1 FROM feo_utilizers WHERE id=?');
        $q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

    private function boolInput(mixed $value): int
    {
        return in_array((string)$value, ['1','on','true','yes'], true) ? 1 : 0;
    }
}
