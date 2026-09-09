<?php

namespace App\Service;

use PDOException;
use Throwable;

final class WasteTripIncidentService
{
    public const DEFAULT_RELATIVE_LOG = 'logs/waste_trip_incidents.jsonl';

    public static function record(Throwable $e, array $context = []): string
    {
        $incident = self::incidentId();
        $record = [
            'incident_id' => $incident,
            'timestamp_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'operation' => self::token((string) ($context['operation'] ?? 'request'), 'request'),
            'legacy_id' => max(0, (int) ($context['legacy_id'] ?? 0)),
            'stage' => self::token((string) ($context['stage'] ?? 'unknown'), 'unknown'),
            'exception_class' => get_class($e),
            'sqlstate' => $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : '',
            'driver_code' => $e instanceof PDOException ? (string) ($e->errorInfo[1] ?? '') : '',
            'message' => self::safeMessage($e->getMessage()),
            'deployed_sha' => self::deployedSha(),
        ];
        foreach (['company_id', 'actor_user_id', 'actor_membership_id'] as $key) {
            $value = (int) ($context[$key] ?? 0);
            if ($value > 0) $record[$key] = $value;
        }
        $role = self::token((string) ($context['actor_role'] ?? ''), '');
        if ($role !== '') $record['actor_role'] = $role;
        $method = strtoupper(self::token((string) ($context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? '')), ''));
        if ($method !== '') $record['request_method'] = substr($method, 0, 12);
        $path = parse_url((string) ($context['request_path'] ?? ($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
        if (is_string($path) && $path !== '') $record['request_path'] = substr($path, 0, 240);

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || !self::append($json . PHP_EOL)) {
            error_log('waste_trip_incident_log_failure incident=' . $incident . ' stage=' . $record['stage']);
        }
        return $incident;
    }

    public static function logPath(): string
    {
        $override = trim((string) getenv('PLANEX_WASTE_TRIP_INCIDENT_LOG'));
        return $override !== '' ? $override : self::applicationRoot() . '/' . self::DEFAULT_RELATIVE_LOG;
    }

    private static function append(string $line): bool
    {
        $path = self::logPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return false;
        return @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === strlen($line);
    }

    private static function incidentId(): string
    {
        try { $suffix = strtoupper(bin2hex(random_bytes(3))); }
        catch (Throwable) { $suffix = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 6)); }
        return 'WT-' . gmdate('ymd-His') . '-' . $suffix;
    }

    private static function token(string $value, string $fallback): string
    {
        $value = preg_replace('/[^a-z0-9_-]+/i', '_', trim($value)) ?? '';
        return substr(trim($value, '_') ?: $fallback, 0, 80);
    }

    private static function safeMessage(string $message): string
    {
        $message = preg_replace('/[\\r\\n\\t]+/', ' ', trim($message)) ?? '';
        $message = preg_replace('/\\b(password|passwd|pwd|token|secret|cookie|csrf|authorization)\\s*[:=]\\s*[^ ]+/i', '$1=[redacted]', $message) ?? $message;
        return function_exists('mb_substr') ? mb_substr($message, 0, 800, 'UTF-8') : substr($message, 0, 800);
    }

    private static function applicationRoot(): string
    {
        if (function_exists('base_path')) {
            return rtrim((string) \base_path(), '/');
        }
        return dirname(__DIR__, 2);
    }

    private static function deployedSha(): ?string
    {
        $envSha = strtolower(trim((string) getenv('PLANEX_DEPLOYED_SHA')));
        if (preg_match('/^[0-9a-f]{40}$/D', $envSha) === 1) return $envSha;
        $marker = dirname(self::applicationRoot(), 2) . '/deploy/P07_erpv2_deployed_commit.txt';
        if (!is_readable($marker)) return null;
        $sha = strtolower(trim((string) @file_get_contents($marker)));
        return preg_match('/^[0-9a-f]{40}$/D', $sha) === 1 ? $sha : null;
    }
}
