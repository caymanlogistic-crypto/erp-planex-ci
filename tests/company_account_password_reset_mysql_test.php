<?php

declare(strict_types=1);

use App\Service\AccountAuthenticationService;
use App\Service\CompanyAccountPasswordResetService;

$root = dirname(__DIR__);
require_once $root . '/app/Service/IdentityNormalizer.php';
require_once $root . '/app/Service/AccountAuthenticationService.php';
require_once $root . '/app/Service/CompanyAccountPasswordResetService.php';

$dbName = getenv('PLANEX_TEST_FIXED_DB') ?: '';
$allowlist = array_filter(explode(',', getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') ?: ''));
if ($dbName === '' || !in_array($dbName, $allowlist, true)) {
    throw new RuntimeException('Disposable test database required.');
}
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1',
    (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306),
    $dbName
);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$central = new PDO($dsn, getenv('PLANEX_SCHEMA_DB_USER') ?: '', getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '', $options);
$tenant = new PDO($dsn, getenv('PLANEX_SCHEMA_DB_USER') ?: '', getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '', $options);

$central->exec('CREATE TABLE companies (id INT PRIMARY KEY, name VARCHAR(100), status VARCHAR(20)) ENGINE=InnoDB');
$central->exec('CREATE TABLE user_accounts (id INT PRIMARY KEY, login VARCHAR(100), normalized_login VARCHAR(100), full_name VARCHAR(100), password_hash VARCHAR(255), status VARCHAR(20), updated_at DATETIME NULL) ENGINE=InnoDB');
$central->exec('CREATE TABLE company_memberships (id INT PRIMARY KEY, account_id INT, company_id INT, status VARCHAR(20)) ENGINE=InnoDB');
$central->exec('CREATE TABLE users (id INT PRIMARY KEY, membership_id INT, login VARCHAR(100), password_hash VARCHAR(255), updated_at DATETIME NULL) ENGINE=InnoDB');
$central->exec("INSERT INTO companies VALUES (7,'PLANEX','active'),(8,'Second','active')");
$oldHash = password_hash('Old-Sidorov-Password', PASSWORD_DEFAULT);
$insert = $central->prepare("INSERT INTO user_accounts VALUES (101,'sidorov','sidorov','Сидоров Михаил',?,'active',NULL)");
$insert->execute([$oldHash]);
$central->exec("INSERT INTO company_memberships VALUES (11,101,7,'active'),(12,101,8,'active')");
$insert = $central->prepare("INSERT INTO users VALUES (33,11,'sidorov',?,NULL),(44,12,'sidorov',?,NULL)");
$insert->execute([$oldHash, $oldHash]);

$reset = new CompanyAccountPasswordResetService($central, $tenant);
$auth = new AccountAuthenticationService($central);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$reset->reset(7, 33, 'New-Sidorov-Password');
$assert($auth->authenticate('sidorov', 'Old-Sidorov-Password') === null, 'Old password still logs in.');
$signedIn = $auth->authenticate('sidorov', 'New-Sidorov-Password');
$assert($signedIn !== null && count($signedIn['memberships']) === 2, 'New password does not log in across memberships.');
$tenantHash = (string) $tenant->query('SELECT password_hash FROM users WHERE id = 33')->fetchColumn();
$assert(password_verify('New-Sidorov-Password', $tenantHash), 'Tenant projection was not updated.');

try {
    $reset->reset(8, 33, 'Wrong-Company-Password');
    throw new RuntimeException('Foreign company reset succeeded.');
} catch (RuntimeException $error) {
    $assert($error->getMessage() !== 'Foreign company reset succeeded.', 'Foreign company reset succeeded.');
}
$assert($auth->authenticate('sidorov', 'New-Sidorov-Password') !== null, 'Failed reset changed central password.');
$assert($auth->authenticate('sidorov', 'Wrong-Company-Password') === null, 'Foreign company password became valid.');

$tenant->exec("UPDATE users SET login='other' WHERE id=33");
try {
    $reset->reset(7, 33, 'Mismatched-Identity-Password');
    throw new RuntimeException('Mismatched identity reset succeeded.');
} catch (RuntimeException $error) {
    $assert($error->getMessage() !== 'Mismatched identity reset succeeded.', 'Mismatched identity reset succeeded.');
}
$assert($auth->authenticate('sidorov', 'New-Sidorov-Password') !== null, 'Identity mismatch changed central password.');

echo "COMPANY_ACCOUNT_PASSWORD_RESET=PASS\n";
