<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class MaxEventNotificationService
{
    public function __construct(private readonly PDO $pdo, private readonly ?MaxMessagingService $messaging = null) {}

    public function ready(): bool
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('max_event_rules','max_event_deliveries')")->fetchColumn() === 2;
    }

    public function rules(): array
    {
        $saved = [];
        if ($this->ready()) {
            foreach ($this->pdo->query('SELECT * FROM max_event_rules')->fetchAll(PDO::FETCH_ASSOC) as $row) $saved[$row['event_key']] = $row;
        }
        $out = [];
        foreach (MaxEventCatalog::all() as $key => $event) {
            $out[] = ($saved[$key] ?? [
                'event_key' => $key, 'is_enabled' => 0, 'chat_config_id' => null,
                'message_template' => $event['template'], 'message_format' => 'plain', 'notify' => 1,
            ]) + $event;
        }
        return $out;
    }

    public function saveRule(array $data, ?int $userId): void
    {
        if (!$this->ready()) throw new RuntimeException('Настройки событий ещё не подключены.');
        $key = (string)($data['event_key'] ?? '');
        $template = trim((string)($data['message_template'] ?? ''));
        $format = (string)($data['message_format'] ?? 'plain');
        MaxEventCatalog::render($key, $template, $format, MaxEventCatalog::example($key));
        $enabled = !empty($data['is_enabled']);
        $chatId = max(0, (int)($data['chat_config_id'] ?? 0));
        if ($enabled && $chatId === 0) throw new RuntimeException('Для включения события выберите чат.');
        if ($chatId > 0) {
            $chat = $this->chat($chatId);
            if ($chat === null) throw new RuntimeException('Чат этой компании не найден.');
            if ($enabled && !$this->chatActive($chat)) throw new RuntimeException('Выберите активный чат с активным ботом.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO max_event_rules (event_key,is_enabled,chat_config_id,message_template,message_format,notify,updated_by_user_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),chat_config_id=VALUES(chat_config_id),message_template=VALUES(message_template),message_format=VALUES(message_format),notify=VALUES(notify),updated_by_user_id=VALUES(updated_by_user_id)');
        $stmt->execute([$key, $enabled ? 1 : 0, $chatId ?: null, $template, $format, !empty($data['notify']) ? 1 : 0, $userId]);
    }

    /** Called inside the business transaction. No HTTP here; rollback also removes the delivery. */
    public function enqueue(string $key, string $entityKey, array $values, ?int $userId): ?int
    {
        MaxEventCatalog::event($key);
        if (!$this->ready()) return null; // Safe code rollout before separately authorized migration.
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Событие MAX должно сохраняться вместе с операцией.');
        if ($entityKey === '' || strlen($entityKey) > 100) throw new RuntimeException('Некорректный идентификатор события.');
        $q = $this->pdo->prepare('SELECT * FROM max_event_rules WHERE event_key=? AND is_enabled=1');
        $q->execute([$key]);
        $rule = $q->fetch(PDO::FETCH_ASSOC);
        if (!$rule) return null;
        $chat = $this->chat((int)$rule['chat_config_id']);
        $error = null;
        $text = '';
        try {
            $text = MaxEventCatalog::render($key, $rule['message_template'], $rule['message_format'], $values);
            if (!$chat || !$this->chatActive($chat)) throw new RuntimeException('Чат или бот отключён. Проверьте настройки MAX.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $stmt = $this->pdo->prepare('INSERT INTO max_event_deliveries (event_key,entity_key,chat_config_id,bot_id,external_chat_id,message_text,message_format,notify,initiated_by_user_id,status,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key, $entityKey, (int)$rule['chat_config_id'], (int)($chat['bot_id'] ?? 0), (string)($chat['chat_id'] ?? ''), $text, $rule['message_format'], (int)$rule['notify'], $userId, $error === null ? 'pending' : 'failed', $error]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Claim once before HTTP. A timeout is not automatically retried (MAX may have accepted it). */
    public function dispatch(int $id, bool $retry = false): array
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Отправка MAX возможна только после сохранения операции.');
        $q = $this->pdo->prepare('SELECT * FROM max_event_deliveries WHERE id=?');
        $q->execute([$id]);
        $delivery = $q->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) throw new RuntimeException('Уведомление не найдено.');
        if ($delivery['status'] === 'sent') return $delivery;
        if ($retry) {
            // Do not reclaim an active request. Stale claims require explicit owner retry.
            $reset = $this->pdo->prepare("UPDATE max_event_deliveries SET status='pending' WHERE id=? AND (status='failed' OR (status='sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)))");
            $reset->execute([$id]);
        }
        $claim = $this->pdo->prepare("UPDATE max_event_deliveries SET status='sending',attempts=attempts+1,error_message=NULL,updated_at=NOW() WHERE id=? AND status='pending'");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) return $delivery;
        try {
            $rule = $this->pdo->prepare('SELECT is_enabled FROM max_event_rules WHERE event_key=?');
            $rule->execute([$delivery['event_key']]);
            if (!(bool)$rule->fetchColumn()) throw new RuntimeException('Отправка этого события отключена.');
            $chat = $this->chat((int)$delivery['chat_config_id']);
            if (!$chat || (int)$chat['bot_id'] !== (int)$delivery['bot_id'] || (string)$chat['chat_id'] !== (string)$delivery['external_chat_id']) {
                throw new RuntimeException('Адрес сохранённого чата изменился. Отправка по старому событию остановлена.');
            }
            $result = ($this->messaging ?? new MaxMessagingService($this->pdo))->sendToChat(
                (int)$delivery['chat_config_id'], $delivery['message_text'], $delivery['message_format'], (bool)$delivery['notify'], 'event',
                $delivery['initiated_by_user_id'] !== null ? (int)$delivery['initiated_by_user_id'] : null,
                ['bot_id' => $delivery['bot_id'], 'external_chat_id' => $delivery['external_chat_id']]
            );
            $mid = $result['message']['body']['mid'] ?? null;
            $update = $this->pdo->prepare("UPDATE max_event_deliveries SET status='sent',max_message_id=?,error_message=NULL WHERE id=?");
            $update->execute([is_scalar($mid) ? (string)$mid : null, $id]);
        } catch (Throwable $e) {
            $update = $this->pdo->prepare("UPDATE max_event_deliveries SET status='failed',error_message=? WHERE id=?");
            $update->execute([mb_substr($e->getMessage(), 0, 2000), $id]);
        }
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function recentDeliveries(): array
    {
        if (!$this->ready()) return [];
        return $this->pdo->query('SELECT d.*,c.name AS chat_name FROM max_event_deliveries d LEFT JOIN max_chats c ON c.id=d.chat_config_id ORDER BY d.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function chat(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT c.*,b.is_active AS bot_active FROM max_chats c JOIN max_bots b ON b.id=c.bot_id WHERE c.id=?');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function chatActive(array $chat): bool
    {
        return !empty($chat['is_active']) && !empty($chat['bot_active']) && $chat['max_status'] === 'active';
    }
}
