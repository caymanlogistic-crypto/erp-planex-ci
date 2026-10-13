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

    $database=(string)getenv('PLANEX_TEST_FIXED_DB');
    if($database===''||!in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)){
        throw new \RuntimeException('Guarded test database required');
    }
    $pdo=new \PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
        getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,membership_id INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE waste_trips(id INT PRIMARY KEY,responsible_user_id INT NOT NULL,created_by_membership_id INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users VALUES(10,71),(11,72)');
    $pdo->exec('INSERT INTO waste_trips VALUES(1,10,72),(2,11,71)');
    $scopeMethod=new \ReflectionMethod(WasteTripClosingService::class,'closingVisibilityScope');
    $visible=static function()use($pdo,$scopeMethod): array {
        $scope=$scopeMethod->invoke(null);
        $stmt=$pdo->prepare('SELECT wt.id FROM waste_trips wt WHERE '.$scope['sql'].' ORDER BY wt.id');
        $stmt->execute($scope['params']);
        return array_map('intval',$stmt->fetchAll(\PDO::FETCH_COLUMN));
    };
    if($visible()!==[1])throw new \RuntimeException('Own scope must follow responsible manager, not trip creator');
    AccessControlService::$membershipId=72;
    if($visible()!==[2])throw new \RuntimeException('Another manager sees only their assigned trip');
    AccessControlService::$membershipId=null;
    if($visible()!==[])throw new \RuntimeException('Unresolved membership must fail closed');
    AccessControlService::$scope='all';
    if($visible()!==[1,2])throw new \RuntimeException('All scope includes both trips');
    AccessRuntime::$active=false;
    $_SESSION=['role_code'=>'logist','user_id'=>10];
    if($visible()!==[1])throw new \RuntimeException('Legacy logist scope follows responsible user');
    echo "WASTE_TRIP_CLOSING_MANAGER_SCOPE=PASS\n";
}
