<?php

namespace App\Service;

use RuntimeException;
use Throwable;

final class GoogleSheetsRequestContactService
{
    public const DEFAULT_SPREADSHEET_ID = '1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4';
    public const DEFAULT_GID = '1200010467';
    public const ID_COLUMN = 0;
    public const CONTACTS_COLUMN = 9;
    public const MAX_CSV_BYTES = 8 * 1024 * 1024;
    public const DEFAULT_CSV_URL = 'https://docs.google.com/spreadsheets/d/' . self::DEFAULT_SPREADSHEET_ID . '/export?format=csv&gid=' . self::DEFAULT_GID;

    /** @var callable|null */
    private static $testDefaultFetcher = null;
    /** @var callable|null */
    private $fetcher;
    private string $url;

    public function __construct(?callable $fetcher = null, ?string $url = null)
    {
        $this->fetcher = $fetcher ?? self::$testDefaultFetcher;
        $configured = trim((string)($url ?? getenv('GOOGLE_SHEETS_FEO_CSV_URL') ?: self::DEFAULT_CSV_URL));
        $this->url = $configured !== '' ? $configured : self::DEFAULT_CSV_URL;
        $this->assertAllowedUrl($this->url);
    }

    public static function setDefaultFetcherForTests(?callable $fetcher): void
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Test fetcher override is CLI-only.');
        self::$testDefaultFetcher = $fetcher;
    }

    /**
     * @param array<int,string|int> $requestIds
     * @return array{available:bool,contacts:array<string,string>,warning:?string,source:array<string,mixed>}
     */
    public function loadContacts(array $requestIds): array
    {
        $wanted = [];
        foreach ($requestIds as $requestId) {
            $id = trim((string)$requestId);
            if ($id !== '') $wanted[$id] = true;
        }
        $contacts = array_fill_keys(array_keys($wanted), '—');

        try {
            $csv = $this->fetchCsv();
            foreach ($this->parseCsv($csv) as $index => $row) {
                // Canonical owner reference timeline.php skips the header and maps row[0] -> row[9].
                if ($index === 0 || !isset($row[self::ID_COLUMN])) continue;
                $id = trim((string)$row[self::ID_COLUMN]);
                if ($id === '' || !is_numeric($id) || !isset($wanted[$id])) continue;
                $value = trim((string)($row[self::CONTACTS_COLUMN] ?? ''));
                $contacts[$id] = $value !== '' ? $value : '—';
            }

            return [
                'available' => true,
                'contacts' => $contacts,
                'warning' => null,
                'source' => $this->sourceMetadata(),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'contacts' => $contacts,
                'warning' => 'Не удалось получить контакты заявок из Google Sheets. Контакты в документе будут указаны как «—». Данные из карточки заявки ФЭО не используются как резервный источник.',
                'source' => $this->sourceMetadata(),
            ];
        }
    }

    private function fetchCsv(): string
    {
        if ($this->fetcher !== null) {
            $payload = ($this->fetcher)($this->url);
            if (!is_string($payload) || $payload === '' || strlen($payload) > self::MAX_CSV_BYTES) throw new RuntimeException('Google Sheets CSV is empty or too large.');
            return $payload;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => false,
                'follow_location' => 1,
                'max_redirects' => 4,
                'header' => "Accept: text/csv\r\nUser-Agent: ERP-PLANEX-DocumentGenerator/1.0\r\n",
            ],
        ]);
        $payload = @file_get_contents($this->url, false, $context);
        if (!is_string($payload) || $payload === '' || strlen($payload) > self::MAX_CSV_BYTES) throw new RuntimeException('Google Sheets CSV unavailable or too large.');
        return $payload;
    }

    /** @return array<int,array<int,string|null>> */
    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Cannot create CSV stream.');
        try {
            if (fwrite($stream, $csv) === false) throw new RuntimeException('Cannot buffer CSV.');
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream)) !== false) $rows[] = $row;
            if ($rows === []) throw new RuntimeException('Google Sheets CSV contains no rows.');
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'docs.google.com'
            || !preg_match('~^/spreadsheets/d/[A-Za-z0-9_-]+/export$~', (string)($parts['path'] ?? ''))
        ) {
            throw new RuntimeException('Google Sheets contacts source URL is not allowed.');
        }
    }

    /** @return array<string,mixed> */
    private function sourceMetadata(): array
    {
        $parts = parse_url($this->url);
        $query = [];
        if (is_array($parts)) parse_str((string)($parts['query'] ?? ''), $query);
        $spreadsheetId = '';
        if (is_array($parts) && preg_match('~^/spreadsheets/d/([A-Za-z0-9_-]+)/export$~', (string)($parts['path'] ?? ''), $m)) {
            $spreadsheetId = (string)$m[1];
        }
        return [
            'kind' => 'google_sheets_csv',
            'spreadsheet_id' => $spreadsheetId,
            'gid' => (string)($query['gid'] ?? ''),
            'id_column' => self::ID_COLUMN,
            'contacts_column' => self::CONTACTS_COLUMN,
        ];
    }
}
