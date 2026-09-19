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
            $row = ($saved[$key] ?? [
                'event_key' => $key, 'is_enabled' => 0, 'chat_config_id' => null,
                'message_template' => $event['template'], 'message_format' => 'plain', 'notify' => 1,
            ]) + $event;
            $row['message_template'] = MaxEventCatalog::currentTemplate($key, (string)$row['message_template']);
            $out[] = $row;
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
            $template = MaxEventCatalog::currentTemplate($key, (string)$rule['message_template']);
            $text = MaxEventCatalog::render($key, $template, $rule['message_format'], $values);
            if (!$chat || !$this->chatActive($chat)) throw new RuntimeException('Чат или бот отключён. Проверьте настройки MAX.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $stmt = $this->pdo->prepare('INSERT INTO max_event_deliveries (event_key,entity_key,chat_config_id,bot_id,external_chat_id,message_text,message_format,notify,initiated_by_user_id,status,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key, $entityKey, (int)$rule['chat_config_id'], (int)($chat['bot_id'] ?? 0), (string)($chat['chat_id'] ?? ''), $text, $rule['message_format'], (int)$rule['notify'], $userId, $error === null ? 'pending' : 'failed', $error]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Save a pre-rendered delivery inside the same business transaction. */
    public function enqueuePrepared(
        string $key,
        string $entityKey,
        string $text,
        string $format,
        ?int $userId,
        ?string $error = null
    ): ?int {
        MaxEventCatalog::event($key);
        if (!$this->ready()) return null;
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Событие MAX должно сохраняться вместе с операцией.');
        if ($entityKey === '' || strlen($entityKey) > 100) throw new RuntimeException('Некорректный идентификатор события.');
        if (!in_array($format, ['plain','html','markdown'], true)) throw new RuntimeException('Неизвестный формат MAX.');
        if ($error === null && (trim($text) === '' || mb_strlen($text, 'UTF-8') > 4000)) {
            throw new RuntimeException('Готовое сообщение должно содержать от 1 до 4000 символов.');
        }
        $q = $this->pdo->prepare('SELECT * FROM max_event_rules WHERE event_key=? AND is_enabled=1');
        $q->execute([$key]);
        $rule = $q->fetch(PDO::FETCH_ASSOC);
        if (!$rule) return null;
        $chat = $this->chat((int)$rule['chat_config_id']);
        if (!$chat) throw new RuntimeException('Чат события MAX не найден.');
        if ($error === null && !$this->chatActive($chat)) $error = 'Чат или бот отключён. Проверьте настройки MAX.';
        $stmt = $this->pdo->prepare('INSERT INTO max_event_deliveries (event_key,entity_key,chat_config_id,bot_id,external_chat_id,message_text,message_format,notify,initiated_by_user_id,status,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key,$entityKey,(int)$rule['chat_config_id'],(int)$chat['bot_id'],(string)$chat['chat_id'],$text,$format,(int)$rule['notify'],$userId,$error===null?'pending':'failed',$error]);
        return (int)$this->pdo->lastInsertId();
    }

    public function failPending(array $ids, string $error): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return;
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->pdo->prepare("UPDATE max_event_deliveries SET status='failed',error_message=?,updated_at=NOW() WHERE status='pending' AND id IN (" . $marks . ")");
            $q->execute(array_merge([mb_substr($error, 0, 2000)], $chunk));
        }
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
        if (preg_match('/^b([1-9][0-9]*):/', (string)$delivery['entity_key'], $match) === 1) {
            $main = $this->pdo->prepare('SELECT status FROM max_event_deliveries WHERE event_key=? AND entity_key=?');
            $main->execute([$delivery['event_key'], $match[1]]);
            if ($main->fetchColumn() !== 'sent') {
                $error = 'Основное уведомление блока не доставлено. Сначала повторите его отправку.';
                $this->pdo->prepare("UPDATE max_event_deliveries SET status='failed',error_message=?,updated_at=NOW() WHERE id=? AND status<>'sent'")
                    ->execute([$error, $id]);
                $q->execute([$id]);
                return $q->fetch(PDO::FETCH_ASSOC);
            }
        }
        // Diagnostic deliveries (for example, a missing responsible MAX ID) deliberately have no sendable body.
        if (trim((string)$delivery['message_text']) === '') return $delivery;
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

    /** Retry/send supplemental deliveries only after the main block delivery is sent. */
    public function dispatchBlockSupplementals(int $blockId, bool $retry = false): array
    {
        if ($blockId <= 0) return [];
        $q = $this->pdo->prepare(
            "SELECT id FROM max_event_deliveries
             WHERE event_key='feo.placement.block_created' AND entity_key LIKE ?
             ORDER BY id"
        );
        $q->execute(['b' . $blockId . ':%']);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $out[] = $this->dispatch((int)$id, $retry);
        return $out;
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
