<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class MaxMessagingService
{
    /** @var callable|null */
    private $clientFactory;

    public function __construct(private readonly PDO $pdo, ?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory;
    }

    public function listBots(): array
    {
        $rows = $this->pdo->query('SELECT * FROM `max_bots` ORDER BY `is_active` DESC, `name`, `id`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'redactBot'], $rows);
    }

    public function listChats(): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.*, b.name AS bot_name, b.is_active AS bot_is_active
               FROM `max_chats` c
               JOIN `max_bots` b ON b.id=c.bot_id
              ORDER BY c.is_active DESC, c.name, c.id'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeChats(): array
    {
        $stmt = $this->pdo->query(
            "SELECT c.*, b.name AS bot_name
               FROM `max_chats` c
               JOIN `max_bots` b ON b.id=c.bot_id
              WHERE c.is_active=1 AND b.is_active=1 AND c.max_status='active'
              ORDER BY c.name, c.id"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recentLogs(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query(
            'SELECT l.*, b.name AS bot_name, c.name AS chat_name
               FROM `max_message_logs` l
               LEFT JOIN `max_bots` b ON b.id=l.bot_id
               LEFT JOIN `max_chats` c ON c.id=l.chat_config_id
              ORDER BY l.id DESC LIMIT ' . $limit
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveBot(array $data): int
    {
        $id = max(0, (int)($data['id'] ?? 0));
        $name = trim((string)($data['name'] ?? ''));
        $token = trim((string)($data['token'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Укажите название бота MAX до 120 символов.');
        }

        $existing = $id > 0 ? $this->getBotRaw($id) : null;
        if ($id > 0 && $existing === null) {
            throw new RuntimeException('Бот MAX не найден.');
        }
        if ($token === '' && $existing === null) {
            throw new RuntimeException('При создании бота укажите API-токен MAX.');
        }

        $tokenEncrypted = $token !== '' ? cryptoEncrypt($token) : (string)$existing['token_encrypted'];
        $secretEncrypted = $existing !== null && (string)$existing['webhook_secret_encrypted'] !== ''
            ? (string)$existing['webhook_secret_encrypted']
            : cryptoEncrypt(self::generateWebhookSecret());
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE `max_bots`
                    SET `name`=:name, `token_encrypted`=:token, `webhook_secret_encrypted`=:secret,
                        `is_active`=:active,
                        `verified_at`=IF(:token_changed=1,NULL,`verified_at`),
                        `bot_user_id`=IF(:token_changed2=1,NULL,`bot_user_id`),
                        `bot_first_name`=IF(:token_changed3=1,NULL,`bot_first_name`),
                        `bot_username`=IF(:token_changed4=1,NULL,`bot_username`),
                        `webhook_status`=IF(:token_changed5=1,\'not_configured\',`webhook_status`),
                        `webhook_last_error`=IF(:token_changed6=1,NULL,`webhook_last_error`)
                  WHERE `id`=:id'
            );
            $changed = $token !== '' ? 1 : 0;
            $stmt->execute([
                ':name' => $name,
                ':token' => $tokenEncrypted,
                ':secret' => $secretEncrypted,
                ':active' => $isActive,
                ':token_changed' => $changed,
                ':token_changed2' => $changed,
                ':token_changed3' => $changed,
                ':token_changed4' => $changed,
                ':token_changed5' => $changed,
                ':token_changed6' => $changed,
                ':id' => $id,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO `max_bots` (`name`,`token_encrypted`,`webhook_secret_encrypted`,`is_active`)
             VALUES (:name,:token,:secret,:active)'
        );
        $stmt->execute([
            ':name' => $name,
            ':token' => $tokenEncrypted,
            ':secret' => $secretEncrypted,
            ':active' => $isActive,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function verifyBot(int $botId): array
    {
        $bot = $this->requireBotRaw($botId);
        $me = $this->clientForBot($bot)->getMe();
        if (empty($me['is_bot'])) {
            throw new RuntimeException('Указанный токен MAX не принадлежит боту.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE `max_bots` SET `bot_user_id`=:uid,`bot_first_name`=:first_name,`bot_username`=:username,`verified_at`=NOW() WHERE id=:id'
        );
        $stmt->execute([
            ':uid' => isset($me['user_id']) ? (string)$me['user_id'] : null,
            ':first_name' => isset($me['first_name']) ? mb_substr((string)$me['first_name'], 0, 255) : null,
            ':username' => isset($me['username']) ? mb_substr((string)$me['username'], 0, 255) : null,
            ':id' => $botId,
        ]);
        return $me;
    }

    public function saveChat(array $data): int
    {
        $id = max(0, (int)($data['id'] ?? 0));
        $botId = (int)($data['bot_id'] ?? 0);
        $chatId = trim((string)($data['chat_id'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $purpose = trim((string)($data['purpose'] ?? ''));
        if ($botId <= 0) {
            throw new RuntimeException('Выберите бота MAX.');
        }
        if (!preg_match('/^-?\d+$/', $chatId)) {
            throw new RuntimeException('chat_id MAX должен быть целым числом.');
        }
        if ($name !== '' && mb_strlen($name) > 160) {
            throw new RuntimeException('Название группы не может быть длиннее 160 символов.');
        }
        if (mb_strlen($purpose) > 500) {
            throw new RuntimeException('Комментарий группы не может быть длиннее 500 символов.');
        }

        $bot = $this->requireBotRaw($botId);
        $chat = $this->clientForBot($bot)->getChat($chatId);
        $type = $this->normalizeChatType((string)($chat['type'] ?? 'unknown'));
        $status = $this->normalizeChatStatus((string)($chat['status'] ?? 'unknown'));
        $title = trim((string)($chat['title'] ?? ''));
        if ($name === '') {
            $name = $title !== '' ? $title : 'MAX ' . $chatId;
        }
        $isActive = !empty($data['is_active']) && $status === 'active' ? 1 : 0;

        if ($id > 0) {
            $existing = $this->getChat($id);
            if ($existing === null) {
                throw new RuntimeException('Группа MAX не найдена.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE `max_chats` SET `bot_id`=:bot_id,`chat_id`=:chat_id,`name`=:name,`max_title`=:title,
                 `chat_type`=:type,`max_status`=:status,`purpose`=:purpose,`is_active`=:active,
                 `discovered_via`=\'manual\',`last_verified_at`=NOW() WHERE id=:id'
            );
            $stmt->execute([
                ':bot_id' => $botId, ':chat_id' => $chatId, ':name' => $name, ':title' => $title !== '' ? $title : null,
                ':type' => $type, ':status' => $status, ':purpose' => $purpose !== '' ? $purpose : null,
                ':active' => $isActive, ':id' => $id,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO `max_chats` (`bot_id`,`chat_id`,`name`,`max_title`,`chat_type`,`max_status`,`purpose`,`is_active`,`discovered_via`,`last_verified_at`)
             VALUES (:bot_id,:chat_id,:name,:title,:type,:status,:purpose,:active,\'manual\',NOW())
             ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`max_title`=VALUES(`max_title`),`chat_type`=VALUES(`chat_type`),
                `max_status`=VALUES(`max_status`),`purpose`=VALUES(`purpose`),`is_active`=VALUES(`is_active`),`last_verified_at`=NOW()'
        );
        $stmt->execute([
            ':bot_id' => $botId, ':chat_id' => $chatId, ':name' => $name, ':title' => $title !== '' ? $title : null,
            ':type' => $type, ':status' => $status, ':purpose' => $purpose !== '' ? $purpose : null, ':active' => $isActive,
        ]);
        $newId = (int)$this->pdo->lastInsertId();
        if ($newId > 0) return $newId;
        $find = $this->pdo->prepare('SELECT id FROM `max_chats` WHERE bot_id=? AND chat_id=?');
        $find->execute([$botId, $chatId]);
        return (int)$find->fetchColumn();
    }

    public function setChatActive(int $chatConfigId, bool $active): void
    {
        $chat = $this->requireChat($chatConfigId);
        if ($active && (string)$chat['max_status'] !== 'active') {
            throw new RuntimeException('Нельзя включить группу MAX, пока её статус в MAX не active. Сначала выполните проверку группы.');
        }
        $stmt = $this->pdo->prepare('UPDATE `max_chats` SET `is_active`=? WHERE id=?');
        $stmt->execute([$active ? 1 : 0, $chatConfigId]);
    }

    public function verifyChat(int $chatConfigId): array
    {
        $chat = $this->requireChat($chatConfigId);
        $bot = $this->requireBotRaw((int)$chat['bot_id']);
        $remote = $this->clientForBot($bot)->getChat((string)$chat['chat_id']);
        $status = $this->normalizeChatStatus((string)($remote['status'] ?? 'unknown'));
        $type = $this->normalizeChatType((string)($remote['type'] ?? 'unknown'));
        $title = trim((string)($remote['title'] ?? ''));
        $stmt = $this->pdo->prepare(
            'UPDATE `max_chats` SET `max_title`=:title,`chat_type`=:type,`max_status`=:status,
             `is_active`=IF(:status2=\'active\',`is_active`,0),`last_verified_at`=NOW() WHERE id=:id'
        );
        $stmt->execute([':title' => $title !== '' ? $title : null, ':type' => $type, ':status' => $status, ':status2' => $status, ':id' => $chatConfigId]);
        return $remote;
    }

    public function configureWebhook(int $botId, string $url): array
    {
        $bot = $this->requireBotRaw($botId);
        $secret = cryptoDecrypt((string)$bot['webhook_secret_encrypted']);
        try {
            $result = $this->clientForBot($bot)->createSubscription($url, $secret);
            if (array_key_exists('success', $result) && !$result['success']) {
                throw new RuntimeException(trim((string)($result['message'] ?? 'MAX отклонил Webhook-подписку.')));
            }
            $stmt = $this->pdo->prepare("UPDATE `max_bots` SET webhook_status='active',webhook_last_error=NULL WHERE id=?");
            $stmt->execute([$botId]);
            return $result;
        } catch (Throwable $e) {
            $stmt = $this->pdo->prepare("UPDATE `max_bots` SET webhook_status='error',webhook_last_error=? WHERE id=?");
            $stmt->execute([mb_substr($e->getMessage(), 0, 1000), $botId]);
            throw $e;
        }
    }

    public function sendToChat(int $chatConfigId, string $text, string $format, bool $notify, string $source, ?int $userId, ?array $expectedDestination = null): array
    {
        if (!in_array($source, ['test', 'manual', 'event'], true)) {
            throw new RuntimeException('Неизвестный источник MAX-сообщения.');
        }
        $chat = $this->requireChat($chatConfigId);
        $bot = $this->requireBotRaw((int)$chat['bot_id']);
        if ($expectedDestination !== null && ((int)$bot['id'] !== (int)$expectedDestination['bot_id'] || (string)$chat['chat_id'] !== (string)$expectedDestination['external_chat_id'])) {
            throw new RuntimeException('Адрес чата MAX изменился после создания уведомления.');
        }
        if (empty($chat['is_active']) || (string)$chat['max_status'] !== 'active' || empty($bot['is_active'])) {
            throw new RuntimeException('Выбранная группа или бот MAX неактивны.');
        }
        if ((string)$chat['chat_type'] === 'channel') {
            $notify = true;
        }

        try {
            $result = $this->clientForBot($bot)->sendMessage((string)$chat['chat_id'], $text, $format, $notify);
            $mid = $result['message']['body']['mid'] ?? null;
            $this->logMessage($bot, $chat, $source, $text, $format, $notify, 'sent', is_scalar($mid) ? (string)$mid : null, null, $userId);
            return $result;
        } catch (Throwable $e) {
            $this->logMessage($bot, $chat, $source, $text, $format, $notify, 'failed', null, $e->getMessage(), $userId);
            throw $e;
        }
    }

    public function handleWebhook(int $botId, string $providedSecret, array $update): void
    {
        $bot = $this->requireBotRaw($botId);
        $expectedSecret = cryptoDecrypt((string)$bot['webhook_secret_encrypted']);
        if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            throw new RuntimeException('WEBHOOK_SECRET_MISMATCH');
        }

        $type = trim((string)($update['update_type'] ?? ''));
        $chatId = isset($update['chat_id']) && is_scalar($update['chat_id']) ? trim((string)$update['chat_id']) : '';
        if ($chatId === '' || !preg_match('/^-?\d+$/', $chatId)) {
            return;
        }

        if ($type === 'bot_added') {
            $chatType = !empty($update['is_channel']) ? 'channel' : 'chat';
            $stmt = $this->pdo->prepare(
                'INSERT INTO `max_chats` (`bot_id`,`chat_id`,`name`,`chat_type`,`max_status`,`is_active`,`discovered_via`,`last_verified_at`)
                 VALUES (:bot,:chat,:name,:type,\'active\',1,\'webhook\',NOW())
                 ON DUPLICATE KEY UPDATE `chat_type`=VALUES(`chat_type`),`max_status`=\'active\',`is_active`=1,`last_verified_at`=NOW()'
            );
            $stmt->execute([':bot' => $botId, ':chat' => $chatId, ':name' => 'MAX ' . $chatId, ':type' => $chatType]);
            return;
        }

        if ($type === 'bot_removed') {
            $stmt = $this->pdo->prepare("UPDATE `max_chats` SET max_status='removed',is_active=0,last_verified_at=NOW() WHERE bot_id=? AND chat_id=?");
            $stmt->execute([$botId, $chatId]);
            return;
        }

        if ($type === 'chat_title_changed') {
            $title = trim((string)($update['title'] ?? $update['chat_title'] ?? ''));
            if ($title !== '') {
                $stmt = $this->pdo->prepare('UPDATE `max_chats` SET max_title=?,last_verified_at=NOW() WHERE bot_id=? AND chat_id=?');
                $stmt->execute([mb_substr($title, 0, 255), $botId, $chatId]);
            }
        }
    }

    public function webhookSecretMasked(int $botId): string
    {
        $bot = $this->requireBotRaw($botId);
        return (string)$bot['webhook_secret_encrypted'] !== '' ? '••••••••' : '—';
    }

    private function clientForBot(array $bot): MaxApiClient
    {
        $token = cryptoDecrypt((string)$bot['token_encrypted']);
        if ($this->clientFactory !== null) {
            $client = ($this->clientFactory)($token);
            if (!$client instanceof MaxApiClient) {
                throw new RuntimeException('Некорректная фабрика MAX API client.');
            }
            return $client;
        }
        return new MaxApiClient($token);
    }

    private function getBotRaw(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `max_bots` WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function requireBotRaw(int $id): array
    {
        $row = $this->getBotRaw($id);
        if ($row === null) throw new RuntimeException('Бот MAX не найден.');
        return $row;
    }

    private function getChat(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `max_chats` WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function requireChat(int $id): array
    {
        $row = $this->getChat($id);
        if ($row === null) throw new RuntimeException('Группа MAX не найдена.');
        return $row;
    }

    private function redactBot(array $bot): array
    {
        $bot['has_token'] = (string)($bot['token_encrypted'] ?? '') !== '';
        $bot['has_webhook_secret'] = (string)($bot['webhook_secret_encrypted'] ?? '') !== '';
        unset($bot['token_encrypted'], $bot['webhook_secret_encrypted']);
        return $bot;
    }

    private function logMessage(array $bot, array $chat, string $source, string $text, string $format, bool $notify, string $status, ?string $mid, ?string $error, ?int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `max_message_logs` (`bot_id`,`chat_config_id`,`external_chat_id`,`source`,`message_text`,`message_format`,`notify`,`status`,`max_message_id`,`error_message`,`initiated_by_user_id`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$bot['id'], (int)$chat['id'], (string)$chat['chat_id'], $source, $text, $format, $notify ? 1 : 0,
            $status, $mid, $error !== null ? mb_substr($error, 0, 2000) : null, $userId,
        ]);
    }

    private function normalizeChatType(string $type): string
    {
        return in_array($type, ['chat', 'channel', 'dialog'], true) ? $type : 'unknown';
    }

    private function normalizeChatStatus(string $status): string
    {
        return in_array($status, ['active', 'removed', 'left', 'closed'], true) ? $status : 'unknown';
    }

    private static function generateWebhookSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
