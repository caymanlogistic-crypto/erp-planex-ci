<?php

namespace App\Service;

use App\Security\AccessRuntime;

/**
 * AccessControlService — единая будущая точка проверки прав доступа внутри company ERP.
 *
 * НАЗНАЧЕНИЕ:
 *   Убрать размазанные по index.php проверки created_by_user_id / entity_access_grants
 *   в один сервис с чёткими методами.
 *
 * СТАТУС: FOUNDATION — НЕ ПОДКЛЮЧЁН к рабочим routes.
 *   Сервис готов к использованию в новых модулях (driver_vehicle_blocks, crews).
 *   Существующие проверки в DO_NOT_TOUCH_WORKING_CORE не меняются.
 *
 * МОДЕЛЬ РОЛЕЙ:
 *   superadmin     — управляет компаниями, видит всё, не вмешивается в локальные данные
 *   company_owner  — руководитель компании, видит все записи, управляет пользователями
 *   senior_logist  — Логист+, видит все записи компании, НЕ управляет пользователями
 *   logist         — обычный логист, видит только свои записи + grants
 *
 * ТЕХНИЧЕСКИЕ ИМЕНА РОЛЕЙ (поле role_code):
 *   'superadmin'
 *   'company_owner'
 *   'senior_logist'
 *   'logist'
 *
 * Примечание: роль senior_logist технически спроектирована, но в UI и routes
 *   пока не активирована. Отображаемое имя: «Логист+».
 */

final class AccessControlService
{
    /** Current central membership id; null means unresolved/denied/technical superadmin. */
    public static function effectiveMembershipId(): ?int
    {
        return AccessRuntime::current()->membershipId();
    }

    public static function isEffectiveSystemOwner(): bool
    {
        return AccessRuntime::current()->isSystemOwner();
    }

    /** @return 'all'|'own'|'none' */
    public static function effectiveDataScope(string $entityType): string
    {
        return AccessRuntime::current()->dataScope($entityType);
    }

    public static function canAccessPage(string $pageKey): bool
    {
        return AccessRuntime::current()->canAccessPage($pageKey);
    }

    public static function hasEntityAccess(\PDO $pdo, string $entityType, int $entityId, int $userId, array $levels = ['view', 'edit']): bool
    {
        if ($entityId <= 0 || $userId <= 0) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($levels), '?'));
        $stmt = $pdo->prepare(
            "SELECT 1 FROM entity_access_grants
             WHERE entity_type = ?
               AND entity_id = ?
               AND granted_to_user_id = ?
               AND access_level IN ($placeholders)
               AND revoked_at IS NULL
             LIMIT 1"
        );
        $stmt->execute(array_merge([$entityType, $entityId, $userId], $levels));

        return (bool) $stmt->fetchColumn();
    }

    public static function hasRouteExecutorAccess(\PDO $pdo, array $crew, int $userId, string $mode = 'view'): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ((int) ($crew['created_by_user_id'] ?? 0) === $userId) {
            return true;
        }

        $levels = $mode === 'edit' ? ['edit'] : ['view', 'edit'];
        $crewId = (int) ($crew['id'] ?? $crew['crew_id'] ?? 0);
        if (self::hasEntityAccess($pdo, 'crew', $crewId, $userId, $levels)) {
            return true;
        }

        $contractorId = (int) ($crew['contractor_id'] ?? 0);
        $driverVehicleBlockId = (int) ($crew['driver_vehicle_block_id'] ?? 0);
        $driverId = (int) ($crew['driver_id'] ?? 0);
        $vehicleSetId = (int) ($crew['vehicle_set_id'] ?? 0);

        $hasContractor = ((int) ($crew['contractor_created_by_user_id'] ?? 0) === $userId)
            || self::hasEntityAccess($pdo, 'contractor', $contractorId, $userId, $levels);

        $hasDriverVehicleBlock = ((int) ($crew['dvb_created_by_user_id'] ?? 0) === $userId)
            || self::hasEntityAccess($pdo, 'driver_vehicle_block', $driverVehicleBlockId, $userId, $levels);

        $hasDriver = ((int) ($crew['driver_created_by_user_id'] ?? 0) === $userId)
            || self::hasEntityAccess($pdo, 'driver', $driverId, $userId, $levels);

        $hasVehicleSet = ((int) ($crew['vehicle_set_created_by_user_id'] ?? 0) === $userId)
            || self::hasEntityAccess($pdo, 'vehicle_set', $vehicleSetId, $userId, $levels);

        return $hasContractor && ($hasDriverVehicleBlock || ($hasDriver && $hasVehicleSet));
    }

    // -------------------------------------------------------------------------
    // Константы ролей
    // -------------------------------------------------------------------------

    public const ROLE_SUPERADMIN    = 'superadmin';
    public const ROLE_COMPANY_OWNER = 'company_owner';
    public const ROLE_SENIOR_LOGIST = 'senior_logist';
    public const ROLE_LOGIST        = 'logist';

    /**
     * Все роли, которые считаются «внутренними» пользователями компании
     * (имеют записи в локальной БД users).
     */
    public const COMPANY_ROLES = [
        self::ROLE_COMPANY_OWNER,
        self::ROLE_SENIOR_LOGIST,
        self::ROLE_LOGIST,
    ];

    /**
     * Роли, которые видят все записи компании (без фильтрации по created_by_user_id).
     */
    public const FULL_ACCESS_ROLES = [
        self::ROLE_SUPERADMIN,
        self::ROLE_COMPANY_OWNER,
        self::ROLE_SENIOR_LOGIST,
    ];

    /**
     * Роли, которые могут управлять пользователями компании
     * (создавать, редактировать, блокировать logist/senior_logist).
     */
    public const USER_MANAGEMENT_ROLES = [
        self::ROLE_SUPERADMIN,
        self::ROLE_COMPANY_OWNER,
    ];

    // -------------------------------------------------------------------------
    // Допустимые entity_type для grants и ownership
    // -------------------------------------------------------------------------

    /**
     * TODO: при расширении списка сущностей — дополнить массив.
     * Сейчас синхронизирован с DECISIONS.md #25.
     */
    public const ALLOWED_ENTITY_TYPES = [
        'client',
        'contractor',
        'driver',
        'vehicle_unit',
        'vehicle_set',
        'driver_vehicle_block',
        'crew',
        'linear_route',
        'logist',   // для grants на пользователей
    ];

    /**
     * entity_type, для которых в таблицах есть поле created_by_user_id.
     * TODO: синхронизировать с миграцией 008 после добавления новых таблиц.
     */
    public const ENTITY_TYPES_WITH_OWNERSHIP = [
        'client',
        'contractor',
        'driver',
        'vehicle_unit',
        'vehicle_set',
        'driver_vehicle_block',
        'crew',
        'linear_route',
    ];

    // -------------------------------------------------------------------------
    // Проверка ролей
    // -------------------------------------------------------------------------

    /**
     * Принимает массив $user с ключами:
     *   - role_code  (string)
     *   - user_id    (int)
     *
     * Массив может быть сессионным: ['role_code' => $_SESSION['role_code'], 'user_id' => $_SESSION['user_id']]
     * или строкой БД: строкой из users / company_users.
     */
    public static function isSuperadmin(array $user): bool
    {
        return ($user['role_code'] ?? '') === self::ROLE_SUPERADMIN;
    }

    public static function isCompanyOwner(array $user): bool
    {
        return ($user['role_code'] ?? '') === self::ROLE_COMPANY_OWNER;
    }

    public static function isSeniorLogist(array $user): bool
    {
        return ($user['role_code'] ?? '') === self::ROLE_SENIOR_LOGIST;
    }

    public static function isLogist(array $user): bool
    {
        return ($user['role_code'] ?? '') === self::ROLE_LOGIST;
    }

    /**
     * Роль принадлежит компании (имеет запись в локальной БД users).
     */
    public static function isCompanyRole(array $user): bool
    {
        return in_array(($user['role_code'] ?? ''), self::COMPANY_ROLES, true);
    }

    // -------------------------------------------------------------------------
    // Проверка уровня доступа к данным компании
    // -------------------------------------------------------------------------

    /**
     * Видит все записи компании без фильтрации по created_by_user_id.
     * superadmin / company_owner / senior_logist.
     */
    public static function canSeeAllCompanyData(array $user): bool
    {
        return in_array(($user['role_code'] ?? ''), self::FULL_ACCESS_ROLES, true);
    }

    /**
     * Может управлять пользователями компании
     * (создавать, редактировать, блокировать).
     */
    public static function canManageCompanyUsers(array $user): bool
    {
        return in_array(($user['role_code'] ?? ''), self::USER_MANAGEMENT_ROLES, true);
    }

    // -------------------------------------------------------------------------
    // Проверка доступа к конкретной сущности
    // -------------------------------------------------------------------------

    /**
     * Имеет ли пользователь право просматривать сущность.
     *
     * $entity — строка БД сущности. Ожидаемые ключи:
     *   - created_by_user_id (int|null) — кто создал
     *   - entity_type        (string)   — для будущих проверок grants
     *   - entity_id          (int)      — для будущих проверок grants
     *
     * TODO: когда grants-проверка будет реализована сервисом,
     *   метод примет PDO и сам выполнит запрос к entity_access_grants.
     *   Сейчас проверяет только created_by_user_id.
     */
    public static function canViewOwnedEntity(array $user, array $entity): bool
    {
        if (self::canSeeAllCompanyData($user)) {
            return true;
        }

        $userId = (int) ($user['user_id'] ?? 0);
        $ownerId = (int) ($entity['created_by_user_id'] ?? 0);

        if ($userId > 0 && $ownerId === $userId) {
            return true;
        }

        // TODO: добавить проверку entity_access_grants, когда сервис
        //       получит доступ к PDO. Сигнатура станет:
        //       canViewOwnedEntity(array $user, array $entity, ?PDO $pdo = null): bool
        //       Если $pdo передан — проверять grants.

        return false;
    }

    /**
     * Имеет ли пользователь право редактировать сущность.
     *
     * Логика: company_owner/senior_logist/superadmin — могут всё.
     * logist — только свои записи (created_by_user_id) + grants с access_level='edit'.
     *
     * TODO: grants-проверка будет добавлена при интеграции с PDO.
     */
    public static function canEditOwnedEntity(array $user, array $entity): bool
    {
        if (self::canSeeAllCompanyData($user)) {
            return true;
        }

        $userId = (int) ($user['user_id'] ?? 0);
        $ownerId = (int) ($entity['created_by_user_id'] ?? 0);

        if ($userId > 0 && $ownerId === $userId) {
            return true;
        }

        // TODO: добавить проверку entity_access_grants с access_level = 'edit'
        //       canEditOwnedEntity(array $user, array $entity, ?PDO $pdo = null): bool

        return false;
    }

    /**
     * Универсальная проверка доступа к сущности.
     *
     * $action: 'view' | 'edit' | 'delete' | 'archive'
     *
     * TODO: 'delete' и 'archive' требуют уточнения бизнес-правил:
     *       может ли logist архивировать свою запись?
     *       может ли senior_logist удалять чужие записи?
     */
    public static function canAccessEntity(array $user, array $entity, string $action): bool
    {
        return match ($action) {
            'view'    => self::canViewOwnedEntity($user, $entity),
            'edit'    => self::canEditOwnedEntity($user, $entity),
            'delete', 'archive' => self::canSeeAllCompanyData($user),
            default   => false,
        };
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы для построения SQL-фильтров
    // -------------------------------------------------------------------------

    /**
     * Возвращает WHERE-условие и параметры для фильтрации списка сущностей,
     * которые logist имеет право видеть.
     *
     * Использование:
     *   $filter = AccessControlService::buildLogistFilter($user, 'driver');
     *   $sql = "SELECT * FROM drivers WHERE " . $filter['where'];
     *   $stmt = $pdo->prepare($sql);
     *   $stmt->execute($filter['params']);
     *
     * Если пользователь имеет полный доступ — возвращает where = '1=1'.
     *
     * TODO: когда grants будут интегрированы, метод должен генерировать
     *   подзапрос к entity_access_grants. Сейчас фильтрует только по created_by_user_id.
     */
    public static function buildLogistFilter(array $user, string $entityType): array
    {
        if (self::canSeeAllCompanyData($user)) {
            return ['where' => '1=1', 'params' => []];
        }

        $userId = (int) ($user['user_id'] ?? 0);

        // TODO: добавить подзапрос к entity_access_grants:
        //   WHERE (created_by_user_id = ? OR id IN (
        //     SELECT entity_id FROM entity_access_grants
        //     WHERE entity_type = ? AND granted_to_user_id = ?
        //     AND access_level = 'view' AND revoked_at IS NULL
        //   ))
        //   Сигнатура станет: buildLogistFilter(array $user, string $entityType, ?PDO $pdo = null)

        return [
            'where'  => 'created_by_user_id = ?',
            'params' => [$userId],
        ];
    }

    // -------------------------------------------------------------------------
    // Проверка допустимости entity_type для grants
    // -------------------------------------------------------------------------

    /**
     * Допустим ли entity_type для создания grants.
     */
    public static function isAllowedEntityType(string $entityType): bool
    {
        return in_array($entityType, self::ALLOWED_ENTITY_TYPES, true);
    }

    /**
     * Есть ли у сущности поле created_by_user_id.
     */
    public static function hasOwnershipColumn(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES_WITH_OWNERSHIP, true);
    }

    // -------------------------------------------------------------------------
    // Отображаемые имена ролей
    // -------------------------------------------------------------------------

    /**
     * Человекочитаемое имя роли для UI.
     */
    public static function roleLabel(string $roleCode): string
    {
        return match ($roleCode) {
            self::ROLE_SUPERADMIN    => 'Суперадминистратор',
            self::ROLE_COMPANY_OWNER => 'Руководитель',
            self::ROLE_SENIOR_LOGIST => 'Логист+',
            self::ROLE_LOGIST        => 'Логист',
            default                  => $roleCode !== '' ? $roleCode : '—',
        };
    }

    /**
     * Допустимые роли для создания пользователя компании
     * (кого может создать company_owner).
     */
    public static function allowedRolesForCompanyUserCreation(): array
    {
        return [
            self::ROLE_LOGIST        => self::roleLabel(self::ROLE_LOGIST),
            self::ROLE_SENIOR_LOGIST => self::roleLabel(self::ROLE_SENIOR_LOGIST),
        ];
    }
}
