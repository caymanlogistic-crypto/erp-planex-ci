<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\MaxMessagingService;
use App\Service\MaxEventCatalog;
use App\Service\MaxEventNotificationService;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class MaxMessagingController
{
    private const SETTINGS_PATH = '/company/max/settings';
    private const MESSAGES_PATH = '/company/max/messages';

    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {}

    public function settings(): void
    {
        requireRole(['company_owner']);
        [$company, $pdo] = $this->tenant(true);
        $service = new MaxMessagingService($pdo);
        $bots = $service->listBots();
        $chats = $service->listChats();
        $activeChats = $service->activeChats();
        $logs = $service->recentLogs(50);
        $events = new MaxEventNotificationService($pdo);
        $eventsReady = $events->ready();
        $eventRules = $events->rules();
        $draft = $_SESSION['max_event_draft'] ?? null;
        unset($_SESSION['max_event_draft']);
        if (is_array($draft)) {
            foreach ($eventRules as &$rule) {
                if ($rule['event_key'] === ($draft['event_key'] ?? '')) $rule = array_replace($rule, ['is_enabled' => 0, 'notify' => 0], $draft);
            }
            unset($rule);
        }
        $eventDeliveries = $events->recentDeliveries();
        $webhookUrls = [];
        foreach ($bots as $bot) {
            $webhookUrls[(int)$bot['id']] = $this->webhookUrl((int)$company['id'], (int)$bot['id']);
        }

        $pageTitle = 'MAX — интеграция';
        $pageContext = 'Настройки › MAX';
        $topbarCrumbs = [
            ['label' => mb_strtoupper((string)($company['name'] ?? 'Компания')), 'url' => app_url('/company/dashboard')],
            ['label' => 'Настройки', 'url' => null],
            ['label' => 'MAX', 'url' => null],
        ];
        [$successFlash, $errorFlash] = $this->takeFlash();

        ob_start();
        require base_path('app/View/pages/company_max_settings.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function saveBot(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $service = new MaxMessagingService($pdo);
            $id = $service->saveBot($_POST);
            $this->success('Бот MAX сохранён. Теперь проверьте токен кнопкой «Проверить бота».');
            if ((int)($_POST['id'] ?? 0) === 0) {
                try {
                    $me = $service->verifyBot($id);
                    $this->success('Бот MAX сохранён и проверен: ' . (string)($me['first_name'] ?? $me['username'] ?? ('ID ' . $me['user_id'])) . '.');
                } catch (Throwable $e) {
                    $this->error('Бот сохранён, но проверка MAX не прошла: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function verifyBot(string $id): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $me = (new MaxMessagingService($pdo))->verifyBot((int)$id);
            $label = trim((string)($me['first_name'] ?? ''));
            $username = trim((string)($me['username'] ?? ''));
            $this->success('Подключение к MAX успешно: ' . ($label !== '' ? $label : 'бот') . ($username !== '' ? ' (@' . $username . ')' : '') . '.');
        } catch (Throwable $e) {
            $this->error('Проверка бота MAX не выполнена: ' . $e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function configureWebhook(string $id): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [$company, $pdo] = $this->tenant(true);
            $botId = (int)$id;
            $url = $this->webhookUrl((int)$company['id'], $botId);
            (new MaxMessagingService($pdo))->configureWebhook($botId, $url);
            $this->success('Webhook MAX подключён. Новые группы будут появляться после событий bot_added.');
        } catch (Throwable $e) {
            $this->error('Webhook MAX не подключён: ' . $e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function saveChat(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            (new MaxMessagingService($pdo))->saveChat($_POST);
            $this->success('Группа MAX сохранена и проверена через API.');
        } catch (Throwable $e) {
            $this->error('Группа MAX не сохранена: ' . $e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function setChatActive(string $id): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $active = !empty($_POST['active']);
            (new MaxMessagingService($pdo))->setChatActive((int)$id, $active);
            $this->success($active ? 'Группа MAX включена для отправки.' : 'Группа MAX отключена для отправки.');
        } catch (Throwable $e) {
            $this->error('Статус группы MAX не изменён: ' . $e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function verifyChat(string $id): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $chat = (new MaxMessagingService($pdo))->verifyChat((int)$id);
            $this->success('Группа MAX проверена: ' . ((string)($chat['title'] ?? '') !== '' ? (string)$chat['title'] : (string)($chat['chat_id'] ?? '')) . '.');
        } catch (Throwable $e) {
            $this->error('Проверка группы MAX не выполнена: ' . $e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function testSend(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $chatId = (int)($_POST['chat_config_id'] ?? 0);
            $text = trim((string)($_POST['message'] ?? ''));
            $format = $this->format((string)($_POST['format'] ?? 'plain'));
            $notify = !empty($_POST['notify']);
            (new MaxMessagingService($pdo))->sendToChat($chatId, $text, $format, $notify, 'test', $this->userId());
            $this->success('Тестовое сообщение отправлено в MAX.');
        } catch (Throwable $e) {
            $this->error('Тестовая отправка MAX не выполнена: ' . $e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH);
    }

    public function saveEvent(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            (new MaxEventNotificationService($pdo))->saveRule($_POST, $this->userId());
            unset($_SESSION['max_event_draft']);
            $this->success('Настройки события сохранены. Они действуют для новых блоков.');
        } catch (Throwable $e) {
            $_SESSION['max_event_draft'] = array_intersect_key($_POST, array_flip(['event_key','is_enabled','chat_config_id','message_template','message_format','notify']));
            $this->error($e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH . '#max-events');
    }

    public function previewEvent(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            $this->tenant(false);
            $key = (string)($_POST['event_key'] ?? '');
            $text = MaxEventCatalog::render($key, (string)($_POST['message_template'] ?? ''), (string)($_POST['message_format'] ?? 'plain'), MaxEventCatalog::example($key));
            jsonResponse(['success' => true, 'text' => $text]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function retryEvent(string $id): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $row = (new MaxEventNotificationService($pdo))->dispatch((int)$id, true);
            if ($row['status'] !== 'sent') throw new RuntimeException($row['error_message'] ?: 'Отправка уже выполняется. Проверьте результат позже.');
            $this->success('Уведомление отправлено в MAX.');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
        redirect_to(self::SETTINGS_PATH . '#max-events');
    }

    public function composer(): void
    {
        requireRole(['company_owner']);
        [$company, $pdo] = $this->tenant(true);
        $service = new MaxMessagingService($pdo);
        $activeChats = $service->activeChats();
        $logs = $service->recentLogs(30);

        $pageTitle = 'Сообщения MAX';
        $pageContext = 'Операции › Сообщения MAX';
        $topbarCrumbs = [
            ['label' => mb_strtoupper((string)($company['name'] ?? 'Компания')), 'url' => app_url('/company/dashboard')],
            ['label' => 'Операции', 'url' => null],
            ['label' => 'Сообщения MAX', 'url' => null],
        ];
        [$successFlash, $errorFlash] = $this->takeFlash();

        ob_start();
        require base_path('app/View/pages/company_max_messages.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function sendManual(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [, $pdo] = $this->tenant(true);
            $chatIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['chat_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
            if ($chatIds === []) {
                throw new RuntimeException('Выберите хотя бы одну группу MAX.');
            }
            if (count($chatIds) > 20) {
                throw new RuntimeException('За одну ручную отправку можно выбрать не более 20 групп MAX.');
            }
            $text = trim((string)($_POST['message'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('Введите текст сообщения.');
            }
            $format = $this->format((string)($_POST['format'] ?? 'plain'));
            $notify = !empty($_POST['notify']);
            $service = new MaxMessagingService($pdo);
            $sent = 0;
            $failed = [];
            foreach ($chatIds as $chatId) {
                try {
                    $service->sendToChat($chatId, $text, $format, $notify, 'manual', $this->userId());
                    $sent++;
                } catch (Throwable $e) {
                    $failed[] = '#' . $chatId . ': ' . $e->getMessage();
                }
            }
            if ($sent > 0) {
                $this->success('Сообщение MAX отправлено: ' . $sent . ' из ' . count($chatIds) . '.');
            }
            if ($failed !== []) {
                $this->error('Не отправлено в ' . count($failed) . ' групп(ы): ' . implode(' | ', array_slice($failed, 0, 5)));
            }
        } catch (Throwable $e) {
            $this->error('Отправка MAX не выполнена: ' . $e->getMessage());
        }
        redirect_to(self::MESSAGES_PATH);
    }

    public function webhook(string $companyId, string $botId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!ctype_digit($companyId) || !ctype_digit($botId) || (int)$companyId <= 0 || (int)$botId <= 0) {
            http_response_code(404);
            echo '{"ok":false}';
            return;
        }

        try {
            [, $pdo] = $this->tenantByCompanyId((int)$companyId, false);
            $raw = file_get_contents('php://input');
            $update = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($update)) {
                throw new RuntimeException('INVALID_UPDATE');
            }
            $secret = trim((string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? ''));
            (new MaxMessagingService($pdo))->handleWebhook((int)$botId, $secret, $update);
            http_response_code(200);
            echo '{"ok":true}';
        } catch (JsonException) {
            http_response_code(400);
            echo '{"ok":false}';
        } catch (RuntimeException $e) {
            http_response_code($e->getMessage() === 'WEBHOOK_SECRET_MISMATCH' ? 403 : 404);
            echo '{"ok":false}';
        } catch (Throwable) {
            http_response_code(503);
            echo '{"ok":false}';
        }
    }

    private function tenant(bool $applyMigrations): array
    {
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new RuntimeException('Компания не найдена.');
        return $this->tenantByCompanyId($companyId, $applyMigrations);
    }

    private function tenantByCompanyId(int $companyId, bool $applyMigrations): array
    {
        $central = $this->db->connection();
        $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new RuntimeException('Компания не найдена или неактивна.');
        $pdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
        if ($applyMigrations) applyLocalMigrations($pdo);
        return [$company, $pdo, $central];
    }

    private function webhookUrl(int $companyId, int $botId): string
    {
        $suffix = '/max/webhook/' . $companyId . '/' . $botId;
        $webhookPath = app_url($suffix);
        $publicBase = trim((string)(getenv('APP_PUBLIC_URL') ?: ''));

        if ($publicBase !== '') {
            $publicBase = rtrim($publicBase, '/');
            $configuredPath = rtrim((string)(parse_url($publicBase, PHP_URL_PATH) ?: ''), '/');
            $appBase = app_base_path();
            if ($appBase !== '' && $configuredPath !== '' && str_ends_with($configuredPath, $appBase)) {
                return $publicBase . $suffix;
            }
            return $publicBase . $webhookPath;
        }

        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $scheme = $forwardedProto !== '' ? $forwardedProto : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') throw new RuntimeException('Не удалось определить публичный адрес ERP для Webhook MAX.');
        return $scheme . '://' . $host . $webhookPath;
    }

    private function format(string $format): string
    {
        return in_array($format, ['plain', 'markdown', 'html'], true) ? $format : 'plain';
    }

    private function userId(): ?int
    {
        $id = (int)($_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function success(string $message): void
    {
        $_SESSION['max_success'] = $message;
    }

    private function error(string $message): void
    {
        $current = trim((string)($_SESSION['max_error'] ?? ''));
        $_SESSION['max_error'] = $current === '' ? $message : $current . ' ' . $message;
    }

    private function takeFlash(): array
    {
        $success = $_SESSION['max_success'] ?? null;
        $error = $_SESSION['max_error'] ?? null;
        unset($_SESSION['max_success'], $_SESSION['max_error']);
        return [$success, $error];
    }
}
