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
        public static function canAccessPage(string $pageKey): bool { return $pageKey==='company.documents.trip_closing_registry'; }
    }
    require_once __DIR__.'/../app/Service/WasteTripClosingService.php';
}

namespace {
    use App\Security\AccessRuntime;
    use App\Service\AccessControlService;
    use App\Service\WasteTripClosingService;

    $GLOBALS['closingTestPath']='/company/trips/closing-documents';
    function current_app_path(): string { return $GLOBALS['closingTestPath']; }

    $database=(string)getenv('PLANEX_TEST_FIXED_DB');
    if($database===''||!in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)){
        throw new \RuntimeException('Guarded test database required');
    }
    $pdo=new \PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
        getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,membership_id INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE waste_trips(id INT PRIMARY KEY,responsible_user_id INT NOT NULL,created_by_membership_id INT NOT NULL,status VARCHAR(32) NOT NULL,deleted_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users VALUES(10,71),(11,72)');
    $pdo->exec("INSERT INTO waste_trips VALUES(1,10,72,'completed',NULL),(2,11,71,'completed',NULL),(3,10,72,'in_progress',NULL),(4,10,72,'planned_route',NULL)");
    $scopeMethod=new \ReflectionMethod(WasteTripClosingService::class,'closingVisibilityScope');
    $visible=static function()use($pdo,$scopeMethod): array {
        $scope=$scopeMethod->invoke(null);
        $stmt=$pdo->prepare('SELECT wt.id FROM waste_trips wt WHERE '.$scope['sql'].' ORDER BY wt.id');
        $stmt->execute($scope['params']);
        return array_map('intval',$stmt->fetchAll(\PDO::FETCH_COLUMN));
    };
    if($visible()!==[1,3,4])throw new \RuntimeException('Own scope must follow responsible manager, not trip creator');
    AccessControlService::$membershipId=72;
    if($visible()!==[2])throw new \RuntimeException('Another manager sees only their assigned trip');
    AccessControlService::$membershipId=null;
    if($visible()!==[])throw new \RuntimeException('Unresolved membership must fail closed');
    AccessControlService::$scope='all';
    if($visible()!==[1,2,3,4])throw new \RuntimeException('All scope includes every manager trip');
    AccessControlService::$scope='none';
    AccessControlService::$membershipId=71;
    $GLOBALS['closingTestPath']='/company/logistics/closing-documents';
    $registryScope=$scopeMethod->invoke(null);
    $registry=$pdo->prepare('SELECT wt.id FROM waste_trips wt WHERE wt.deleted_at IS NULL AND '.WasteTripClosingService::allowedStatusesSql().' AND '.$registryScope['sql'].' ORDER BY wt.id');
    $registry->execute($registryScope['params']);
    if(array_map('intval',$registry->fetchAll(\PDO::FETCH_COLUMN))!==[1,3])throw new \RuntimeException('Registry page grant must show only assigned started/completed trips');
    $GLOBALS['closingTestPath']='/company/trips/closing-documents';
    if($visible()!==[])throw new \RuntimeException('Legacy collection must retain denied data scope');
    AccessRuntime::$active=false;
    $_SESSION=['role_code'=>'logist','user_id'=>10];
    if($visible()!==[1,3,4])throw new \RuntimeException('Legacy logist scope follows responsible user');
    echo "WASTE_TRIP_CLOSING_MANAGER_SCOPE=PASS\n";
}
