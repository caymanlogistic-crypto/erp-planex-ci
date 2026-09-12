<?php

namespace App\Service;

use RuntimeException;

final class MaxApiClient
{
    public const BASE_URL = 'https://platform-api2.max.ru';

    /** @var callable|null */
    private $transport;

    public function __construct(private readonly string $token, ?callable $transport = null)
    {
        if (trim($this->token) === '') {
            throw new RuntimeException('Токен MAX не настроен.');
        }
        $this->transport = $transport;
    }

    public function getMe(): array
    {
        return $this->request('GET', '/me');
    }

    public function getChat(string $chatId): array
    {
        $chatId = $this->normalizeChatId($chatId);
        return $this->request('GET', '/chats/' . rawurlencode($chatId));
    }

    public function sendMessage(string $chatId, string $text, string $format = 'plain', bool $notify = true): array
    {
        $chatId = $this->normalizeChatId($chatId);
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Текст сообщения MAX пуст.');
        }
        if (mb_strlen($text) > 4000) {
            throw new RuntimeException('Сообщение MAX не может быть длиннее 4000 символов.');
        }
        if (!in_array($format, ['plain', 'markdown', 'html'], true)) {
            throw new RuntimeException('Неподдерживаемый формат сообщения MAX.');
        }

        $body = ['text' => $text, 'notify' => $notify];
        if ($format !== 'plain') {
            $body['format'] = $format;
        }

        return $this->request('POST', '/messages', ['chat_id' => $chatId], $body);
    }

    public function createSubscription(string $url, string $secret): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Webhook MAX должен использовать HTTPS.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{5,256}$/', $secret)) {
            throw new RuntimeException('Секрет Webhook MAX имеет недопустимый формат.');
        }

        return $this->request('POST', '/subscriptions', [], [
            'url' => $url,
            'update_types' => ['bot_added', 'bot_removed', 'chat_title_changed'],
            'secret' => $secret,
        ]);
    }

    public function getSubscriptions(): array
    {
        return $this->request('GET', '/subscriptions');
    }

    private function normalizeChatId(string $chatId): string
    {
        $chatId = trim($chatId);
        if (!preg_match('/^-?\d+$/', $chatId)) {
            throw new RuntimeException('chat_id MAX должен быть целым числом.');
        }
        return $chatId;
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($this->transport !== null) {
            $result = ($this->transport)($method, $url, $body, ['Authorization: ' . $this->token]);
            if (!is_array($result)) {
                throw new RuntimeException('Некорректный ответ транспорта MAX.');
            }
            return $result;
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL недоступен для подключения к MAX API.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось инициализировать соединение с MAX API.');
        }

        $headers = ['Authorization: ' . $this->token, 'Accept: application/json'];
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $caBundle = trim((string)(getenv('MAX_CA_BUNDLE') ?: ''));
        if ($caBundle !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        $raw = curl_exec($ch);
        $curlNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $curlNo !== 0) {
            $hint = $curlNo === 60
                ? ' Проверьте доверенную цепочку сертификатов для platform-api2.max.ru (включая актуальные требования MAX).'
                : '';
            throw new RuntimeException('MAX API недоступен: ' . ($curlError !== '' ? $curlError : 'ошибка соединения') . $hint);
        }

        try {
            $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException) {
            throw new RuntimeException('MAX API вернул некорректный JSON (HTTP ' . $status . ').');
        }
        if (!is_array($data)) {
            $data = [];
        }

        if ($status < 200 || $status >= 300) {
            $message = trim((string)($data['message'] ?? $data['error'] ?? ''));
            throw new RuntimeException('MAX API HTTP ' . $status . ($message !== '' ? ': ' . mb_substr($message, 0, 500) : '.'));
        }

        return $data;
    }
}
