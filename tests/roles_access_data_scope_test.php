<?php

declare(strict_types=1);

namespace App\Security {
    final class AccessRuntime
    {
        public static bool $active = true;
        public static function current(): object
        {
            return new class {
                public function isActive(): bool { return AccessRuntime::$active; }
            };
        }
    }
}

namespace App\Service {
    final class AccessControlService
    {
        public static string $scope = 'none';
        public static ?int $membershipId = null;
        public static bool $owner = false;
        public static function effectiveDataScope(string $entityType): string { return self::$scope; }
        public static function effectiveMembershipId(): ?int { return self::$membershipId; }
        public static function isEffectiveSystemOwner(): bool { return self::$owner; }
    }

    require_once __DIR__ . '/../app/Service/LegacyAccessProjectionService.php';
    require_once __DIR__ . '/../app/Service/RegulatedDataScopeService.php';
    require_once __DIR__ . '/../app/Service/EntityAssignmentService.php';
}

namespace {
    use App\Security\AccessRuntime;
    use App\Service\AccessControlService;
    use App\Service\EntityAssignmentService;
    use App\Service\RegulatedDataScopeService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $assert(EntityAssignmentService::ENTITY_TYPES === ['client','contractor','vehicle_unit','driver'], 'exact assignment types');
    $assert(!EntityAssignmentService::isSupported('vehicle_set'), 'VehicleSet has no normal assignment');
    $assert(!EntityAssignmentService::isSupported('linear_trip'), 'trips have no normal assignment');

    AccessRuntime::$active = true;
    AccessControlService::$scope = 'all';
    $condition = RegulatedDataScopeService::condition('client', 'c.id');
    $assert($condition === ['sql'=>'1 = 1','params'=>[]], 'all scope');

    AccessControlService::$scope = 'none';
    $condition = RegulatedDataScopeService::condition('client', 'c.id');
    $assert($condition === ['sql'=>'1 = 0','params'=>[]], 'none scope');

    AccessControlService::$scope = 'own';
    AccessControlService::$membershipId = null;
    $assert(RegulatedDataScopeService::condition('driver', 'd.id')['sql'] === '1 = 0', 'own fails closed without membership');

    AccessControlService::$membershipId = 42;
    $condition = RegulatedDataScopeService::condition('vehicle_unit', 'vu.id');
    $assert(str_contains($condition['sql'], 'vu.created_by_membership_id') && str_contains($condition['sql'], 'entity_assignments'), 'reference own uses creator projection plus assignments');
    $assert($condition['params'] === [42, 'vehicle_unit', 42], 'reference own key is membership');

    $condition = RegulatedDataScopeService::condition('linear_trip', 'lr.id', 'lr.created_by_membership_id');
    $assert(str_contains($condition['sql'], 'lr.created_by_membership_id') && str_contains($condition['sql'], 'legacy_access_projections'), 'linear trip own preserves creator plus immutable historical projection');
    $assert($condition['params'] === [42, 'linear_trip', 42, 'view', 'edit'], 'linear trip historical visibility parameters');

    AccessRuntime::$active = false;
    $_SESSION = ['role_code' => 'logist', 'user_id' => 9];
    $legacy = RegulatedDataScopeService::condition('linear_trip', 'lr.id', 'lr.created_by_membership_id');
    $assert(str_contains($legacy['sql'], 'lr.created_by_user_id') && str_contains($legacy['sql'], 'entity_access_grants'), 'feature OFF uses factual legacy route authority');
    $assert($legacy['params'] === [9, 'linear_route', 9], 'legacy route parameters preserved');
    $assert(RegulatedDataScopeService::assertCanCreate('client') === 0, 'legacy create is not blocked and does not invent membership');

    $root = dirname(__DIR__);
    $client = file_get_contents($root . '/app/Service/ClientService.php');
    $contractor = file_get_contents($root . '/app/Service/ContractorService.php');
    $driver = file_get_contents($root . '/app/Service/DriverService.php');
    $linear = file_get_contents($root . '/app/Service/LinearRouteService.php');
    $waste = file_get_contents($root . '/app/Service/WasteTripService.php');
    $vehicles = file_get_contents($root . '/app/Http/Routes/company_vehicles.php');
    $reassign = file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/reassign.php');

    foreach ([$client,$contractor,$driver,$linear,$waste,$vehicles] as $source) {
        $assert(str_contains($source, 'RegulatedDataScopeService'), 'regulated surface uses domain scope');
    }
    foreach ([$client,$contractor,$driver,$linear,$waste,$vehicles] as $source) {
        $assert(str_contains($source, 'created_by_membership_id'), 'regulated create preserves membership creator');
    }
    foreach ([$client,$contractor,$driver,$vehicles] as $source) {
        $assert(str_contains($source, 'autoAssignCreator') || str_contains($source, 'createAssigned'), 'reference creation auto-assigns atomically');
    }
    $assert(str_contains($reassign, "isset(\$payload['cascade'])"), 'legacy cascade payload explicitly retired');
    $assert(!str_contains($reassign, 'UPDATE contractors SET created_by_user_id'), 'assignment mutation never rewrites creator');

    echo "PASS roles access data scope\n";
}
