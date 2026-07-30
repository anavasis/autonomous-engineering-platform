<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Append-only JSONL SSH audit log with credential redaction.
 */
final class SSHAuditLog
{
    /** @var list<SSHAuditLogEntry> */
    private array $entries = [];

    public function __construct(
        private readonly ?string $filePath = null,
    ) {
    }

    public function append(SSHAuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
        if ($this->filePath === null || $this->filePath === '') {
            return;
        }

        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create SSH audit log directory: ' . $dir);
        }

        $line = json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        file_put_contents($this->filePath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<SSHAuditLogEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public static function redact(string $message, SSHAuthentication $auth): string
    {
        $redacted = $message;
        $password = $auth->password();
        if ($password !== null && $password !== '') {
            $redacted = str_replace($password, '***', $redacted);
        }
        $key = $auth->privateKey();
        if ($key !== null && $key !== '') {
            $redacted = str_replace($key, '***', $redacted);
        }
        $passphrase = $auth->passphrase();
        if ($passphrase !== null && $passphrase !== '') {
            $redacted = str_replace($passphrase, '***', $redacted);
        }

        // Drop PEM blocks if somehow echoed.
        $redacted = preg_replace(
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/s',
            '***',
            $redacted
        ) ?? $redacted;

        return $redacted;
    }

    public static function redactCommand(string $command, SSHAuthentication $auth): string
    {
        return self::redact($command, $auth);
    }
}
