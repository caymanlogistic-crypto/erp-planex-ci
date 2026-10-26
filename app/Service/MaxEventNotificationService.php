<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/MaxChatMemberNotFoundException.php';

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
            if ($key === 'feo.placement.block_created') {
                $row['feo_templates'] = MaxFeoMessageTemplates::decode((string)$row['message_template']);
                $row['feo_templates_version'] = MaxFeoMessageTemplates::version();
                $row['message_template'] = $row['feo_templates']['main'];
            }
            $row['message_template'] = MaxEventCatalog::currentTemplate($key, (string)$row['message_template']);
            $row['message_format'] = MaxEventCatalog::formatForTemplate($key, (string)$row['message_format'], (string)$row['message_template']);
            if (MaxEventCatalog::isSystemRequired($key)) {
                $row['is_enabled'] = 1;
                $row['message_format'] = MaxEventCatalog::systemFormat($key, (string)$row['message_format']);
                $row['system_required'] = true;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function saveRule(array $data, ?int $userId): void
    {
        if (!$this->ready()) throw new RuntimeException('Настройки событий ещё не подключены.');
        $key = (string)($data['event_key'] ?? '');
        $event = MaxEventCatalog::event($key);
        $systemRequired = MaxEventCatalog::isSystemRequired($key);
        $template = trim((string)($data['message_template'] ?? ''));
        if ($key === 'feo.placement.block_created') {
            if (isset($data['feo_templates'])) {
                $templates = MaxFeoMessageTemplates::fromSubmittedInput(
                    $data['feo_templates'],
                    $data['feo_templates_version'] ?? null
                );
            } else {
                $templates = MaxFeoMessageTemplates::load($this->pdo);
                if ($template !== '') $templates['main'] = $template;
            }
            foreach (array_keys(MaxFeoMessageTemplates::definitions()) as $kind) MaxFeoMessageTemplates::preview($templates,$kind);
            $template = MaxFeoMessageTemplates::encode($templates);
        }
        $format = MaxEventCatalog::formatForTemplate($key, (string)($data['message_format'] ?? 'plain'), $template);
        MaxEventCatalog::render($key, $template, $format, MaxEventCatalog::example($key));
        $enabled = $systemRequired || !empty($data['is_enabled']);
        $chatId = max(0, (int)($data['chat_config_id'] ?? 0));
        if ($enabled && $chatId === 0) {
            throw new RuntimeException($systemRequired ? 'Для системного события ФЭО выберите чат MAX.' : 'Для включения события выберите чат.');
        }
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
        $rule = $this->ruleForEvent($key);
        if (!$rule) return null;
        $chat = $this->chat((int)$rule['chat_config_id']);
        $error = null;
        $text = '';
        $format = MaxEventCatalog::formatForTemplate($key, (string)$rule['message_format'], (string)$rule['message_template']);
        try {
            $template = MaxEventCatalog::currentTemplate($key, (string)$rule['message_template']);
            $text = MaxEventCatalog::render($key, $template, $format, $values);
            if (!$chat || !$this->chatActive($chat)) throw new RuntimeException('Чат или бот отключён. Проверьте настройки MAX.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $stmt = $this->pdo->prepare('INSERT INTO max_event_deliveries (event_key,entity_key,chat_config_id,bot_id,external_chat_id,message_text,message_format,notify,initiated_by_user_id,status,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key, $entityKey, (int)$rule['chat_config_id'], (int)($chat['bot_id'] ?? 0), (string)($chat['chat_id'] ?? ''), $text, $format, (int)$rule['notify'], $userId, $error === null ? 'pending' : 'failed', $error]);
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
        $rule = $this->ruleForEvent($key);
        if (!$rule) return null;
        $chat = $this->chat((int)$rule['chat_config_id']);
        if ($error === null && !$chat) $error = 'Чат события MAX не найден. Проверьте настройки MAX.';
        if ($error === null && !$this->chatActive($chat)) $error = 'Чат или бот отключён. Проверьте настройки MAX.';
        $stmt = $this->pdo->prepare('INSERT INTO max_event_deliveries (event_key,entity_key,chat_config_id,bot_id,external_chat_id,message_text,message_format,notify,initiated_by_user_id,status,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$key,$entityKey,(int)$rule['chat_config_id'],(int)($chat['bot_id'] ?? 0),(string)($chat['chat_id'] ?? ''),$text,$format,(int)$rule['notify'],$userId,$error===null?'pending':'failed',$error]);
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
            if (str_contains((string)$delivery['entity_key'], ':route_warning:')) {
                $parts = $this->pdo->prepare("SELECT COUNT(*) FROM max_event_deliveries WHERE event_key=? AND entity_key LIKE ? AND status<>'sent'");
                $parts->execute([$delivery['event_key'], 'b'.$match[1].':main:p%']);
                if ((int)$parts->fetchColumn() > 0) {
                    $error = 'Части основного уведомления блока не доставлены. Сначала повторите их отправку.';
                    $this->pdo->prepare("UPDATE max_event_deliveries SET status='failed',error_message=?,updated_at=NOW() WHERE id=? AND status<>'sent'")
                        ->execute([$error, $id]);
                    $q->execute([$id]);
                    return $q->fetch(PDO::FETCH_ASSOC);
                }
            }
        }
        if ($delivery['event_key'] === 'feo.request.weight_changed'
            && preg_match('/^weight:batch:([1-9][0-9]*):route_warning:p[1-9][0-9]*$/D', (string)$delivery['entity_key'], $match) === 1) {
            $parts = $this->pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(status<>'sent'),0) unsent FROM max_event_deliveries WHERE event_key=? AND entity_key LIKE ?");
            $parts->execute([$delivery['event_key'], 'weight:batch:'.$match[1].':apply:p%']);
            $main = $parts->fetch(PDO::FETCH_ASSOC);
            if ((int)$main['total'] === 0 || (int)$main['unsent'] > 0) {
                $error = 'Основное уведомление об изменении веса не доставлено. Сначала повторите его отправку.';
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
            $enabled = (bool)$rule->fetchColumn();
            if (!MaxEventCatalog::isSystemRequired((string)$delivery['event_key']) && !$enabled) {
                throw new RuntimeException('Отправка этого события отключена.');
            }
            $chat = $this->chat((int)$delivery['chat_config_id']);
            if (!$chat || (int)$chat['bot_id'] !== (int)$delivery['bot_id'] || (string)$chat['chat_id'] !== (string)$delivery['external_chat_id']) {
                throw new RuntimeException('Адрес сохранённого чата изменился. Отправка по старому событию остановлена.');
            }
            $messaging = $this->messaging ?? new MaxMessagingService($this->pdo);
            $messageText = $this->canonicalizeFeoMentions($messaging, $delivery, (string)$delivery['message_text']);
            if ($messageText !== (string)$delivery['message_text']) {
                $this->pdo->prepare("UPDATE max_event_deliveries SET message_text=? WHERE id=? AND status='sending'")
                    ->execute([$messageText, $id]);
                $delivery['message_text'] = $messageText;
            }
            $result = $messaging->sendToChat(
                (int)$delivery['chat_config_id'], $messageText, $delivery['message_format'], (bool)$delivery['notify'], 'event',
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

    public function rebuildFeoSupplementalForRetry(int $id, PDO $central, int $companyId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM max_event_deliveries WHERE id=?');
        $q->execute([$id]);
        $delivery = $q->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) throw new RuntimeException('Уведомление не найдено.');
        if ($delivery['status'] === 'sent' || (string)$delivery['event_key'] !== 'feo.placement.block_created') return $delivery;
        if ($delivery['status'] === 'sending') {
            $stale = $this->pdo->prepare('SELECT updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) FROM max_event_deliveries WHERE id=?');
            $stale->execute([$id]);
            if (!(bool)$stale->fetchColumn()) return $delivery;
        }
        if (preg_match('/^b([1-9][0-9]*):(same_point|nearby|active_exclusion):r([1-9][0-9]*):p([1-9][0-9]*)$/D', (string)$delivery['entity_key'], $m) !== 1) {
            return $delivery;
        }

        $intent = (new FeoPlacementRouteNotificationService())->intentForDelivery(
            $this->pdo,
            $central,
            $companyId,
            (int)$m[1],
            (string)$m[2],
            (int)$m[3],
            (int)$m[4]
        );
        if ($intent === null) {
            $this->pdo->prepare("UPDATE max_event_deliveries SET message_text='',status='failed',error_message=?,updated_at=NOW() WHERE id=? AND status<>'sent' AND (status<>'sending' OR updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))")
                ->execute(['Адресное уведомление больше не актуально для текущего состояния рейса.', $id]);
        } else {
            $error = isset($intent['error']) ? (string)$intent['error'] : null;
            $this->pdo->prepare("UPDATE max_event_deliveries SET message_text=?,message_format=?,status=?,error_message=?,updated_at=NOW() WHERE id=? AND status<>'sent' AND (status<>'sending' OR updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))")
                ->execute([
                    (string)$intent['text'],
                    (string)$intent['format'],
                    $error === null ? 'pending' : 'failed',
                    $error,
                    $id,
                ]);
        }
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    /** Retry/send supplemental deliveries only after the main block delivery is sent. */
    public function dispatchBlockSupplementals(
        int $blockId,
        bool $retry = false,
        ?PDO $central = null,
        ?int $companyId = null
    ): array {
        if ($blockId <= 0) return [];
        $q = $this->pdo->prepare(
            "SELECT id FROM max_event_deliveries
             WHERE event_key='feo.placement.block_created' AND entity_key LIKE ?
             ORDER BY id"
        );
        $q->execute(['b' . $blockId . ':%']);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            if ($retry && $central !== null && $companyId !== null) {
                $this->rebuildFeoSupplementalForRetry($id, $central, $companyId);
            }
            $out[] = $this->dispatch($id, $retry);
        }
        return $out;
    }

    public function delivery(int $id): ?array
    {
        if (!$this->ready() || $id <= 0) return null;
        $q = $this->pdo->prepare('SELECT * FROM max_event_deliveries WHERE id=?');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function recentDeliveries(): array
    {
        if (!$this->ready()) return [];
        return $this->pdo->query('SELECT d.*,c.name AS chat_name FROM max_event_deliveries d LEFT JOIN max_chats c ON c.id=d.chat_config_id ORDER BY d.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ruleForEvent(string $key): ?array
    {
        if (MaxEventCatalog::isSystemRequired($key)) {
            $q = $this->pdo->prepare('SELECT * FROM max_event_rules WHERE event_key=?');
            $q->execute([$key]);
            $rule = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($rule === null || (int)($rule['chat_config_id'] ?? 0) <= 0) {
                throw new RuntimeException('Системное событие ФЭО не настроено: выберите чат MAX.');
            }
            return $rule;
        }
        $q = $this->pdo->prepare('SELECT * FROM max_event_rules WHERE event_key=? AND is_enabled=1');
        $q->execute([$key]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function canonicalizeFeoMentions(MaxMessagingService $messaging, array $delivery, string $text): string
    {
        if ((string)$delivery['event_key'] !== 'feo.placement.block_created'
            || preg_match('/^b[1-9][0-9]*:/D', (string)$delivery['entity_key']) !== 1
            || !str_contains($text, 'max://user/')) {
            return $text;
        }

        $cache = [];
        $compact = str_contains($text, '[Менеджер](max://user/');
        $result = preg_replace_callback(
            '/\[[^\]]*\]\(max:\/\/user\/([1-9][0-9]{0,18})\)(\s*·\s*)?/u',
            function (array $m) use (&$cache, $messaging, $delivery, $compact): string {
                $userId = (string)$m[1];
                if (!array_key_exists($userId, $cache)) {
                    try {
                        $member = $messaging->resolveChatMember(
                            (int)$delivery['chat_config_id'],
                            $userId,
                            ['bot_id' => $delivery['bot_id'], 'external_chat_id' => $delivery['external_chat_id']]
                        );
                    } catch (MaxChatMemberNotFoundException) {
                        $cache[$userId] = null;
                        return '';
                    }
                    $name = trim((string)($member['first_name'] ?? ''));
                    $last = trim((string)($member['last_name'] ?? ''));
                    if ($last !== '' && (!$compact || $name === '')) $name .= ($name !== '' ? ' ' : '') . $last;
                    if ($name === '') throw new RuntimeException('MAX не вернул имя пользователя ' . $userId . '.');
                    $cache[$userId] = self::markdownLabel($name);
                }
                if ($cache[$userId] === null) return '';
                return '[' . $cache[$userId] . '](max://user/' . $userId . ')' . ($m[2] ?? '');
            },
            $text
        );
        if ($result === null) throw new RuntimeException('Не удалось подготовить MAX mention.');
        return $result;
    }

    private static function markdownLabel(string $value): string
    {
        $value = str_replace(chr(96), '\\' . chr(96), $value);
        return preg_replace('/([\\\\*_{}\[\]()#+.!>~|=\-^])/u', '\\\\$1', $value) ?? $value;
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
