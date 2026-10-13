<?php
declare(strict_types=1);

namespace App\Security {
    final class AccessRuntime
    {
        public static bool $active=true;
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
        public static string $scope='own';
        public static ?int $membershipId=71;
        public static function effectiveDataScope(string $entityType): string { return self::$scope; }
        public static function effectiveMembershipId(): ?int { return self::$membershipId; }
    }
    require_once __DIR__.'/../app/Service/WasteTripClosingService.php';
}

namespace {
    use App\Security\AccessRuntime;
    use App\Service\AccessControlService;
    use App\Service\WasteTripClosingService;

    $method=new \ReflectionMethod(WasteTripClosingService::class,'closingVisibilityScope');
    $scope=$method->invoke(null);
    if($scope['params']!==[71]||!str_contains($scope['sql'],'responsible.id=wt.responsible_user_id')
        ||!str_contains($scope['sql'],'responsible.membership_id=?')
        ||str_contains($scope['sql'],'created_by_membership_id')) {
        throw new \RuntimeException('Manager scope must use responsible user membership');
    }
    AccessControlService::$membershipId=null;
    if($method->invoke(null)!==['sql'=>'1 = 0','params'=>[]])throw new \RuntimeException('Unresolved membership must fail closed');
    AccessControlService::$scope='all';
    if($method->invoke(null)!==['sql'=>'1 = 1','params'=>[]])throw new \RuntimeException('All scope must see every completed trip');
    AccessRuntime::$active=false;
    $_SESSION=['role_code'=>'logist','user_id'=>10];
    if($method->invoke(null)!==['sql'=>'wt.responsible_user_id=?','params'=>[10]])throw new \RuntimeException('Legacy logist scope must follow responsible user');
    echo "WASTE_TRIP_CLOSING_MANAGER_SCOPE_STATIC=PASS\n";
}
