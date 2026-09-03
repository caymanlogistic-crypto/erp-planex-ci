<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Maintains the tenant users row strictly as a technical actor projection.
 * Authentication and authorization authority remain central after cutover.
 */
final class CompanyActorProjectionService
{
    public function __construct(private readonly array $config)
    {
    }

    public function getLocalPdo(array $company): PDO
    {
        $localDbConfig = companyDatabaseConfig($this->config, $company);
        $db = new Database($localDbConfig);
        return $db->connection();
    }

    public function assertProjectionSchema(PDO $localPdo): void
    {
        $required = [
            'id', 'full_name', 'login', 'email', 'phone', 'position', 'password_hash',
            'role_code', 'status', 'global_user_id', 'membership_id',
        ];

        try {
            $columns = $localPdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (\Throwable $e) {
            throw new RuntimeException('Tenant users недоступна. Выполните штатную tenant migration.', 0, $e);
        }

        $missing = array_values(array_diff($required, $columns));
        if ($missing !== []) {
            throw new RuntimeException(
                'Tenant actor projection schema не готова. Отсутствуют: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Link a trustworthy legacy tenant user or create a non-auth actor row.
     * Returns compensation metadata because the caller coordinates a separate
     * central DB transaction and cross-database atomic commit is unavailable.
     *
     * @return array{tenant_user_id:int, change:array}
     */
    public function linkOrCreate(
        PDO $localPdo,
        array $globalUser,
        int $membershipId,
        string $compatibilityRoleCode,
        string $membershipStatus = 'active'
    ): array {
        if ($membershipId <= 0 || (int) ($globalUser['id'] ?? 0) <= 0) {
            throw new RuntimeException('Некорректная actor projection identity.');
        }
        if (!in_array($membershipStatus, ['active', 'blocked', 'archived'], true)) {
            throw new RuntimeException('Некорректный membership status для actor projection.');
        }

        $this->assertProjectionSchema($localPdo);
        $globalUserId = (int) $globalUser['id'];
        $login = trim((string) ($globalUser['login'] ?? ''));
        if ($login === '') {
            throw new RuntimeException('Глобальная учётная запись не имеет логина.');
        }

        $localPdo->beginTransaction();
        try {
            $existing = $this->findExistingProjection($localPdo, $globalUserId, $membershipId, $login);

            if ($existing !== null) {
                $currentGlobal = (int) ($existing['global_user_id'] ?? 0);
                $currentMembership = (int) ($existing['membership_id'] ?? 0);
                if ($currentGlobal > 0 && $currentGlobal !== $globalUserId) {
                    throw new RuntimeException('Tenant login уже связан с другой глобальной учётной записью.');
                }
                if ($currentMembership > 0 && $currentMembership !== $membershipId) {
                    throw new RuntimeException('Tenant actor уже связан с другим membership.');
                }

                $previous = [
                    'full_name' => $existing['full_name'] ?? null,
                    'login' => $existing['login'] ?? null,
                    'email' => $existing['email'] ?? null,
                    'phone' => $existing['phone'] ?? null,
                    'position' => $existing['position'] ?? null,
                    'role_code' => $existing['role_code'] ?? null,
                    'status' => $existing['status'] ?? null,
                    'global_user_id' => $existing['global_user_id'] ?? null,
                    'membership_id' => $existing['membership_id'] ?? null,
                ];

                $stmt = $localPdo->prepare(
                    'UPDATE users
                        SET full_name = ?, login = ?, email = ?, phone = ?, position = ?,
                            role_code = ?, status = ?, global_user_id = ?, membership_id = ?,
                            updated_at = NOW()
                      WHERE id = ?'
                );
                $stmt->execute([
                    (string) ($globalUser['full_name'] ?? ''),
                    $login,
                    $this->nullableString($globalUser['email'] ?? null),
                    $this->nullableString($globalUser['phone'] ?? null),
                    $this->nullableString($globalUser['position'] ?? null),
                    $compatibilityRoleCode,
                    $membershipStatus,
                    $globalUserId,
                    $membershipId,
                    (int) $existing['id'],
                ]);

                $tenantUserId = (int) $existing['id'];
                $localPdo->commit();

                return [
                    'tenant_user_id' => $tenantUserId,
                    'change' => [
                        'mode' => 'updated',
                        'tenant_user_id' => $tenantUserId,
                        'previous' => $previous,
                    ],
                ];
            }

            // This hash is intentionally not copied from the global account.
            // The tenant projection must never become a second credential authority.
            $nonAuthHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            $stmt = $localPdo->prepare(
                'INSERT INTO users
                    (full_name, login, email, phone, position, password_hash, role_code, status,
                     global_user_id, membership_id, created_by_user_id, created_by_role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $creatorId = (int) ($_SESSION['user_id'] ?? 0);
            $stmt->execute([
                (string) ($globalUser['full_name'] ?? ''),
                $login,
                $this->nullableString($globalUser['email'] ?? null),
                $this->nullableString($globalUser['phone'] ?? null),
                $this->nullableString($globalUser['position'] ?? null),
                $nonAuthHash,
                $compatibilityRoleCode,
                $membershipStatus,
                $globalUserId,
                $membershipId,
                $creatorId > 0 ? $creatorId : null,
                (string) ($_SESSION['role_code'] ?? 'system'),
            ]);
            $tenantUserId = (int) $localPdo->lastInsertId();
            if ($tenantUserId <= 0) {
                throw new RuntimeException('Не удалось создать tenant actor projection.');
            }

            $localPdo->commit();
            return [
                'tenant_user_id' => $tenantUserId,
                'change' => [
                    'mode' => 'created',
                    'tenant_user_id' => $tenantUserId,
                    'membership_id' => $membershipId,
                ],
            ];
        } catch (\Throwable $e) {
            if ($localPdo->inTransaction()) {
                $localPdo->rollBack();
            }
            throw $e;
        }
    }

    public function compensate(PDO $localPdo, array $change): void
    {
        $mode = (string) ($change['mode'] ?? '');
        $tenantUserId = (int) ($change['tenant_user_id'] ?? 0);
        if ($tenantUserId <= 0 || $mode === '' || $mode === 'none') {
            return;
        }

        if ($mode === 'created') {
            $stmt = $localPdo->prepare('DELETE FROM users WHERE id = ? AND membership_id = ?');
            $stmt->execute([$tenantUserId, (int) ($change['membership_id'] ?? 0)]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('CRITICAL: не удалось компенсировать созданный tenant actor.');
            }
            return;
        }

        if ($mode !== 'updated' || !is_array($change['previous'] ?? null)) {
            throw new RuntimeException('CRITICAL: неизвестный формат компенсации tenant actor.');
        }

        $p = $change['previous'];
        $stmt = $localPdo->prepare(
            'UPDATE users
                SET full_name = ?, login = ?, email = ?, phone = ?, position = ?, role_code = ?,
                    status = ?, global_user_id = ?, membership_id = ?, updated_at = NOW()
              WHERE id = ?'
        );
        $stmt->execute([
            $p['full_name'] ?? '',
            $p['login'] ?? '',
            $p['email'] ?? null,
            $p['phone'] ?? null,
            $p['position'] ?? null,
            $p['role_code'] ?? 'logist',
            $p['status'] ?? 'active',
            $p['global_user_id'] ?? null,
            $p['membership_id'] ?? null,
            $tenantUserId,
        ]);
    }

    public function updateCachedState(PDO $localPdo, int $tenantUserId, string $roleCode, string $status): void
    {
        if ($tenantUserId <= 0 || !in_array($status, ['active', 'blocked', 'archived'], true)) {
            throw new RuntimeException('Некорректное состояние tenant actor.');
        }
        $stmt = $localPdo->prepare(
            'UPDATE users SET role_code = ?, status = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$roleCode, $status, $tenantUserId]);
        if ($stmt->rowCount() === 0) {
            $check = $localPdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $check->execute([$tenantUserId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Tenant actor не найден.');
            }
        }
    }

    private function findExistingProjection(PDO $localPdo, int $globalUserId, int $membershipId, string $login): ?array
    {
        $byMembership = $localPdo->prepare('SELECT * FROM users WHERE membership_id = ? LIMIT 2 FOR UPDATE');
        $byMembership->execute([$membershipId]);
        $rows = $byMembership->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) > 1) {
            throw new RuntimeException('Конфликт tenant membership actor mapping.');
        }
        if ($rows !== []) {
            return $rows[0];
        }

        $byGlobal = $localPdo->prepare('SELECT * FROM users WHERE global_user_id = ? LIMIT 2 FOR UPDATE');
        $byGlobal->execute([$globalUserId]);
        $rows = $byGlobal->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) > 1) {
            throw new RuntimeException('Конфликт tenant global actor mapping.');
        }
        if ($rows !== []) {
            return $rows[0];
        }

        $byLogin = $localPdo->prepare('SELECT * FROM users WHERE login = ? LIMIT 2 FOR UPDATE');
        $byLogin->execute([$login]);
        $rows = $byLogin->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) > 1) {
            throw new RuntimeException('Конфликт tenant login.');
        }
        return $rows[0] ?? null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
