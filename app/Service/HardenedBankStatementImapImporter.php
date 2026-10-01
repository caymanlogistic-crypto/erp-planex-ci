<?php

namespace App\Service;

use PDO;

final class HardenedBankStatementImapImporter
{
    private const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    private string $host;
    private int $port;
    private bool $useSsl;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $subjectContains;

    public function __construct(?array $settings = null)
    {
        $settings ??= [];
        $this->host = (string) ($settings['imap_host'] ?? getenv('BANK_IMAP_HOST') ?: 'imap.spaceweb.ru');
        $this->port = (int) ($settings['imap_port'] ?? getenv('BANK_IMAP_PORT') ?: 993);
        $this->useSsl = array_key_exists('imap_ssl', $settings)
            ? !empty($settings['imap_ssl'])
            : (getenv('BANK_IMAP_SSL') ?: '1') === '1';
        $this->username = (string) ($settings['username'] ?? getenv('BANK_IMAP_USERNAME') ?: '');
        $this->password = isset($settings['password_encrypted'])
            ? cryptoDecrypt((string) $settings['password_encrypted'])
            : (string) (getenv('BANK_IMAP_PASSWORD') ?: '');
        $this->fromAddress = (string) ($settings['sender_filter'] ?? getenv('BANK_IMAP_FROM') ?: 'vtb-inform@vtb.ru');
        $this->subjectContains = (string) ($settings['subject_filter'] ?? getenv('BANK_IMAP_SUBJECT_CONTAINS') ?: 'Регулярная выписка');
    }

    public function importAllRecent(PDO $localPdo, string $companyStorageRoot): array
    {
        if (!extension_loaded('imap')) {
            throw new \RuntimeException('PHP IMAP extension is not installed.');
        }
        if ($this->username === '' || $this->password === '') {
            return [['status' => 'error', 'message' => 'IMAP is not configured.']];
        }
        $this->validateEndpoint();

        $results = [];
        foreach ($this->listMailboxNames() as $mailboxName) {
            $mailbox = $this->openMailbox($mailboxName);
            if ($mailbox === false) {
                $results[] = ['status' => 'error', 'message' => 'Cannot open IMAP folder: ' . $mailboxName];
                continue;
            }
            try {
                $criteria = 'FROM "' . $this->escapeSearchValue($this->fromAddress)
                    . '" SUBJECT "' . $this->escapeSearchValue($this->subjectContains) . '"';
                $emails = imap_search($mailbox, $criteria) ?: [];
                rsort($emails, SORT_NUMERIC);
                foreach (array_slice($emails, 0, 20) as $msgNo) {
                    $results[] = $this->processEmail($mailbox, (int) $msgNo, $localPdo, $mailboxName, new BankStatementFileStore($companyStorageRoot));
                }
            } finally {
                imap_close($mailbox);
            }
        }

        return $results ?: [['status' => 'info', 'message' => 'No matching emails found']];
    }

    private function listMailboxNames(): array
    {
        $mailbox = $this->openMailbox('INBOX');
        if ($mailbox === false) {
            throw new \RuntimeException('Cannot open IMAP mailbox: ' . (imap_last_error() ?: 'unknown error'));
        }
        try {
            return imap_list($mailbox, $this->mailboxRoot(), '*') ?: [$this->mailboxRoot() . 'INBOX'];
        } finally {
            imap_close($mailbox);
        }
    }

    private function mailboxRoot(): string
    {
        return '{' . $this->host . ':' . $this->port . '/imap/ssl/validate-cert}';
    }

    private function openMailbox(string $mailboxName)
    {
        $path = str_starts_with($mailboxName, '{') ? $mailboxName : $this->mailboxRoot() . $mailboxName;
        return @imap_open($path, $this->username, $this->password, OP_READONLY, 0);
    }

    private function processEmail($mailbox, int $msgNo, PDO $localPdo, string $mailboxName, BankStatementFileStore $fileStore): array
    {
        $uid = sha1($mailboxName) . ':' . imap_uid($mailbox, $msgNo);
        $structure = imap_fetchstructure($mailbox, $msgNo, 0);
        if ($structure === false) {
            return ['status' => 'error', 'message' => 'Cannot fetch message structure.', 'uid' => $uid];
        }

        $attachments = [];
        foreach (($structure->parts ?? []) as $index => $part) {
            $this->collectAttachments($part, (string) ($index + 1), $mailbox, $msgNo, $attachments);
        }
        if ($attachments === []) {
            return ['status' => 'skipped', 'message' => 'No valid XLSX attachments.', 'uid' => $uid];
        }

        $importResults = [];
        foreach ($attachments as $attachment) {
            $tmpPath = tempnam(sys_get_temp_dir(), 'planex_bank_');
            $content = $attachment['content'];
            if ($tmpPath === false || file_put_contents($tmpPath, $content, LOCK_EX) !== strlen($content)) {
                if (is_string($tmpPath) && is_file($tmpPath)) {
                    unlink($tmpPath);
                }
                $importResults[] = ['status' => 'error', 'message' => 'Cannot create temporary XLSX.', 'filename' => $attachment['filename']];
                continue;
            }
            $newFile = false;
            $hash = null;
            try {
                $parsed = (new BankStatementXlsxParser())->parse($tmpPath);
                $hash = hash('sha256', $content);
                $newFile = !$fileStore->has($hash);
                $fileStore->save($tmpPath, $hash);
                $result = BankFinanceService::importParsedData(
                    $localPdo,
                    $parsed,
                    'imap',
                    $uid,
                    $attachment['filename'],
                    $hash
                );
                if (($result['status'] ?? '') === 'error' && $newFile) {
                    $fileStore->remove($hash);
                    $newFile = false;
                }
                if (($result['status'] ?? '') === 'error' && in_array($result['message'] ?? '', [
                    'No account number in file',
                    'No financial rows found in file',
                ], true)) {
                    $result['status'] = 'skipped';
                }
                $importResults[] = $result;
            } catch (\Throwable $e) {
                if ($newFile && is_string($hash)) {
                    try { $fileStore->remove($hash); } catch (\Throwable $cleanupError) { error_log('Bank statement file cleanup failed: ' . $cleanupError->getMessage()); }
                }
                $importResults[] = ['status' => 'error', 'message' => 'Parse error: ' . $e->getMessage(), 'filename' => $attachment['filename']];
            } finally {
                if (is_file($tmpPath)) {
                    unlink($tmpPath);
                }
            }
        }

        $overview = imap_fetch_overview($mailbox, $msgNo, 0);
        return [
            'status' => 'processed',
            'message' => 'Processed ' . count($importResults) . ' attachments',
            'uid' => $uid,
            'subject' => $overview[0]->subject ?? '(no subject)',
            'attachments' => $importResults,
        ];
    }

    private function collectAttachments(object $part, string $partNumber, $mailbox, int $msgNo, array &$result): void
    {
        $filename = '';
        foreach (array_merge($part->dparameters ?? [], $part->parameters ?? []) as $parameter) {
            if (in_array(strtolower((string) ($parameter->attribute ?? '')), ['filename', 'name'], true)) {
                $filename = $this->decodeMimeText((string) ($parameter->value ?? ''));
                break;
            }
        }
        if ($filename !== '' && preg_match('/\.xlsx$/i', $filename)) {
            $content = imap_fetchbody($mailbox, $msgNo, $partNumber, FT_PEEK);
            $content = match ((int) ($part->encoding ?? 0)) {
                3 => imap_base64($content),
                4 => imap_qprint($content),
                default => $content,
            };
            if (is_string($content) && $content !== '' && strlen($content) <= self::MAX_ATTACHMENT_BYTES) {
                $result[] = ['filename' => basename(str_replace('\\', '/', $filename)), 'content' => $content];
            }
        }
        foreach (($part->parts ?? []) as $index => $child) {
            $this->collectAttachments($child, $partNumber . '.' . ($index + 1), $mailbox, $msgNo, $result);
        }
    }

    private function decodeMimeText(string $value): string
    {
        $decoded = '';
        foreach (imap_mime_header_decode($value) ?: [] as $piece) {
            $decoded .= (string) ($piece->text ?? '');
        }
        return trim($decoded !== '' ? $decoded : $value);
    }

    private function escapeSearchValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function validateEndpoint(): void
    {
        if (!$this->useSsl) {
            throw new \RuntimeException('IMAP TLS is mandatory.');
        }
        $host = strtolower(rtrim(trim($this->host), '.'));
        if ($host === '' || strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            throw new \RuntimeException('Invalid IMAP host name.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new \RuntimeException('Invalid IMAP port.');
        }
        $allowedHosts = array_filter(array_map(
            static fn (string $item): string => strtolower(rtrim(trim($item), '.')),
            explode(',', (string) (getenv('BANK_IMAP_ALLOWED_HOSTS') ?: ''))
        ));
        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            throw new \RuntimeException('IMAP host is not allowed.');
        }
        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === []) {
            throw new \RuntimeException('IMAP host cannot be resolved.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('IMAP host resolves to a private or reserved address.');
            }
        }
        $this->host = $host;
    }
}
