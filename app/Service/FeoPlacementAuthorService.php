<?php

namespace App\Service;

use PDO;

final class FeoPlacementAuthorService
{
    public function __construct(private readonly PDO $centralPdo)
    {
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    public function decorate(PDO $tenantPdo, int $companyId, array $blocks): array
    {
        if ($blocks === []) return [];

        $tenantIds = [];
        $ownerIds = [];
        $accountIds = [];
        foreach ($blocks as $block) {
            $id = (int)($block['created_by_user_id'] ?? 0);
            if ($id <= 0) continue;
            $role = trim((string)($block['created_by_role'] ?? ''));
            if ($role === 'company_owner') $ownerIds[$id] = true;
            elseif ($role === '') $accountIds[$id] = true;
            else $tenantIds[$id] = true;
        }

        $tenant = $this->tenantUsers($tenantPdo, array_keys($tenantIds));
        $owners = $this->companyOwners($companyId, array_keys($ownerIds));
        $accounts = $this->accounts(array_keys($accountIds));

        foreach ($blocks as &$block) {
            $id = (int)($block['created_by_user_id'] ?? 0);
            $role = trim((string)($block['created_by_role'] ?? ''));
            $identity = null;
            if ($id > 0) {
                if ($role === 'company_owner') $identity = $owners[$id] ?? null;
                elseif ($role === '') $identity = $accounts[$id] ?? null;
                else $identity = $tenant[$id] ?? null;
            }
            $block['created_by_login'] = is_array($identity) ? (string)($identity['login'] ?? '') : '';
            $block['created_by_display_name'] = is_array($identity)
                ? self::shortName((string)($identity['full_name'] ?? ''), (string)($identity['login'] ?? ''))
                : '—';
        }
        unset($block);
        return $blocks;
    }

    public static function shortName(string $fullName, string $fallbackLogin = ''): string
    {
        $fullName = preg_replace('/\s+/u', ' ', trim($fullName)) ?? '';
        if ($fullName === '') return trim($fallbackLogin) !== '' ? trim($fallbackLogin) : '—';
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        if (count($parts) < 2) return $parts[0] ?? (trim($fallbackLogin) !== '' ? trim($fallbackLogin) : '—');
        $first = (string)$parts[0];
        $surname = (string)$parts[count($parts) - 1];
        if (count($parts) >= 3) {
            $second = (string)$parts[1];
            $last = (string)$parts[count($parts) - 1];
            // ERP data contains both «Имя Отчество Фамилия» and
            // «Фамилия Имя Отчество». Detect the latter by patronymic suffix.
            if (self::looksLikePatronymic($last) && !self::looksLikePatronymic($second)) {
                $first = $second;
                $surname = (string)$parts[0];
            }
        }
        $initial = function_exists('mb_substr') ? mb_substr($surname, 0, 1, 'UTF-8') : substr($surname, 0, 1);
        return $initial === '' ? $first : $first . ' ' . $initial . '.';
    }

    private static function looksLikePatronymic(string $value): bool
    {
        return preg_match('/(?:ич|вна|чна|оглы|кызы)$/iu', trim($value)) === 1;
    }

    /** @param list<int|string> $ids @return array<int,array{full_name:string,login:string}> */
    private function tenantUsers(PDO $pdo, array $ids): array
    {
        if ($ids === []) return [];
        $q = $pdo->prepare('SELECT id,full_name,login FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute(array_map('intval', $ids));
        return $this->indexRows($q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int|string> $ids @return array<int,array{full_name:string,login:string}> */
    private function companyOwners(int $companyId, array $ids): array
    {
        if ($companyId <= 0 || $ids === []) return [];
        $params = [$companyId, ...array_map('intval', $ids)];
        $q = $this->centralPdo->prepare('SELECT id,full_name,login FROM company_users WHERE company_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute($params);
        return $this->indexRows($q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int|string> $ids @return array<int,array{full_name:string,login:string}> */
    private function accounts(array $ids): array
    {
        if ($ids === []) return [];
        try {
            $q = $this->centralPdo->prepare('SELECT id,full_name,login FROM user_accounts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute(array_map('intval', $ids));
            return $this->indexRows($q->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array{full_name:string,login:string}> */
    private function indexRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $out[$id] = [
                'full_name' => (string)($row['full_name'] ?? ''),
                'login' => (string)($row['login'] ?? ''),
            ];
        }
        return $out;
    }
}
