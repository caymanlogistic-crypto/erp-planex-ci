<?php

declare(strict_types=1);

use App\Service\MaxApiClient;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Команда доступна только из консоли.\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/MaxApiClient.php';

$options = getopt('', ['group::', 'company-id::', 'json', 'include-bots', 'help']);
if (isset($options['help'])) {
    echo <<<'HELP'
Получение MAX ID участников настроенной группы.

Использование:
  php scripts/max_group_members.php
  php scripts/max_group_members.php --group="ФРЕГАТ"
  php scripts/max_group_members.php --group="ФРЕГАТ" --company-id=25
  php scripts/max_group_members.php --group="ФРЕГАТ" --json
  php scripts/max_group_members.php --group="ФРЕГАТ" --include-bots

По умолчанию ищется группа «ФРЕГАТ» во всех активных компаниях, а боты
исключаются из результата. Команда только читает настройки и MAX API.
HELP;
    echo "\n";
    exit(0);
}

$groupName = trim((string)($options['group'] ?? 'ФРЕГАТ'));
$companyId = isset($options['company-id']) ? (int)$options['company-id'] : 0;
$asJson = isset($options['json']);
$includeBots = isset($options['include-bots']);

if ($groupName === '') {
    throw new RuntimeException('Название группы не может быть пустым.');
}
if (isset($options['company-id']) && $companyId <= 0) {
    throw new RuntimeException('company-id должен быть положительным числом.');
}

$connect = static function (array $dbConfig): PDO {
    return new PDO(
        'mysql:host=' . $dbConfig['host']
        . ';port=' . ($dbConfig['port'] ?? 3306)
        . ';dbname=' . $dbConfig['database']
        . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4'),
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
};

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() === 1;
};

$central = $connect($config['database']);
$companySql = "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
                 FROM companies
                WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>''";
$companyParams = [];
if ($companyId > 0) {
    $companySql .= ' AND id=?';
    $companyParams[] = $companyId;
}
$companySql .= ' ORDER BY id';
$companyStmt = $central->prepare($companySql);
$companyStmt->execute($companyParams);
$companies = $companyStmt->fetchAll();
if ($companies === []) {
    throw new RuntimeException($companyId > 0 ? 'Активная компания не найдена.' : 'Активные компании не найдены.');
}

$normalize = static fn(string $value): string => mb_strtolower(trim($value), 'UTF-8');
$targetName = $normalize($groupName);
$matches = [];

foreach ($companies as $company) {
    $tenantConfig = companyDatabaseConfig($config, $company);
    $tenant = $connect($tenantConfig);
    if ((string)$tenant->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) {
        throw new RuntimeException('Подключена неожиданная БД компании #' . (int)$company['id'] . '.');
    }
    if (!$tableExists($tenant, 'max_chats') || !$tableExists($tenant, 'max_bots')) {
        continue;
    }

    $rows = $tenant->query(
        'SELECT c.id,c.chat_id,c.name,c.max_title,c.max_status,c.is_active,
                b.id AS bot_id,b.name AS bot_name,b.is_active AS bot_is_active,b.token_encrypted
           FROM max_chats c
           JOIN max_bots b ON b.id=c.bot_id
          ORDER BY c.id'
    )->fetchAll();

    foreach ($rows as $row) {
        $configuredName = $normalize((string)($row['name'] ?? ''));
        $maxTitle = $normalize((string)($row['max_title'] ?? ''));
        if ($configuredName !== $targetName && $maxTitle !== $targetName) {
            continue;
        }
        $matches[] = ['company' => $company, 'chat' => $row];
    }
}

if ($matches === []) {
    throw new RuntimeException('Настроенная группа MAX «' . $groupName . '» не найдена.');
}
if (count($matches) > 1) {
    $locations = array_map(
        static fn(array $match): string => sprintf(
            '#%d %s (чат %s)',
            (int)$match['company']['id'],
            (string)$match['company']['name'],
            (string)$match['chat']['chat_id']
        ),
        $matches
    );
    throw new RuntimeException(
        'Найдено несколько групп «' . $groupName . '»: ' . implode(', ', $locations)
        . '. Повторите команду с --company-id.'
    );
}

$match = $matches[0];
$chat = $match['chat'];
$token = cryptoDecrypt((string)$chat['token_encrypted']);
try {
    $members = (new MaxApiClient($token))->getChatMembers((string)$chat['chat_id']);
} finally {
    $token = '';
}

$rows = [];
$skippedBots = 0;
foreach ($members as $member) {
    $isBot = !empty($member['is_bot']);
    if ($isBot && !$includeBots) {
        $skippedBots++;
        continue;
    }
    $firstName = trim((string)($member['first_name'] ?? ''));
    $lastName = trim((string)($member['last_name'] ?? ''));
    $name = trim($firstName . ' ' . $lastName);
    $username = trim((string)($member['username'] ?? ''));
    if ($name === '') {
        $name = $username !== '' ? '@' . ltrim($username, '@') : '—';
    }
    $rows[] = [
        'max_id' => (string)($member['user_id'] ?? ''),
        'name' => $name,
        'username' => $username !== '' ? '@' . ltrim($username, '@') : '',
        'role' => $isBot ? 'бот' : (!empty($member['is_owner']) ? 'владелец' : (!empty($member['is_admin']) ? 'администратор' : 'участник')),
    ];
}

usort($rows, static function (array $left, array $right): int {
    $byName = strnatcasecmp($left['name'], $right['name']);
    return $byName !== 0 ? $byName : strnatcasecmp($left['max_id'], $right['max_id']);
});

$meta = [
    'company_id' => (int)$match['company']['id'],
    'company' => (string)$match['company']['name'],
    'group' => (string)($chat['max_title'] ?: $chat['name']),
    'chat_id' => (string)$chat['chat_id'],
    'members' => count($rows),
    'bots_excluded' => $skippedBots,
];

if ($asJson) {
    echo json_encode(['group' => $meta, 'members' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

printf("Группа: %s | компания: %s (#%d) | chat_id: %s\n", $meta['group'], $meta['company'], $meta['company_id'], $meta['chat_id']);
printf("Людей: %d%s\n\n", $meta['members'], $skippedBots > 0 ? ' | ботов исключено: ' . $skippedBots : '');
echo "MAX ID\tИмя\tНик\tРоль\n";
foreach ($rows as $row) {
    echo $row['max_id'] . "\t" . $row['name'] . "\t" . ($row['username'] !== '' ? $row['username'] : '—') . "\t" . $row['role'] . "\n";
}
