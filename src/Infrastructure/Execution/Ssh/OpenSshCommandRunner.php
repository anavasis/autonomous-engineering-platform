<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * OpenSSH CLI remote command runner.
 *
 * Exactly one SSH process (one connection) per run() call. No pooling.
 * Does not use phpseclib, ext-ssh2, or other SSH libraries.
 */
final class OpenSshCommandRunner implements RemoteCommandRunner
{
    private const MAX_CAPTURE_BYTES = 1_048_576;

    public function __construct(
        private readonly string $sshBinary = 'ssh',
    ) {
    }

    public function run(
        RemoteHost $host,
        SSHAuthentication $authentication,
        string $command,
        float $timeoutSeconds = 0.0,
    ): SSHResult {
        $command = trim($command);
        if ($command === '') {
            return new SSHResult(2, '', 'Remote command must be non-empty.', false, false, 'Empty command.');
        }

        $tempFiles = [];
        try {
            $env = $this->baseEnv();
            $args = [
                $this->sshBinary,
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'ConnectTimeout=' . $host->connectTimeoutSeconds(),
                '-p', (string) $host->port(),
            ];

            if ($authentication->isPrivateKey()) {
                $keyFile = $this->writeTempKey($authentication->privateKey() ?? '');
                $tempFiles[] = $keyFile;
                $args[] = '-i';
                $args[] = $keyFile;
                $args[] = '-o';
                $args[] = 'IdentitiesOnly=yes';

                $passphrase = $authentication->passphrase();
                if ($passphrase !== null && $passphrase !== '') {
                    $ask = $this->writeAskPass($passphrase);
                    $tempFiles = array_merge($tempFiles, $ask['files']);
                    $env = array_merge($env, $ask['env']);
                    $args[] = '-o';
                    $args[] = 'NumberOfPasswordPrompts=1';
                } else {
                    $args[] = '-o';
                    $args[] = 'BatchMode=yes';
                }
            } elseif ($authentication->isPassword()) {
                $ask = $this->writeAskPass($authentication->password() ?? '');
                $tempFiles = array_merge($tempFiles, $ask['files']);
                $env = array_merge($env, $ask['env']);
                $args[] = '-o';
                $args[] = 'PreferredAuthentications=password';
                $args[] = '-o';
                $args[] = 'PubkeyAuthentication=no';
                $args[] = '-o';
                $args[] = 'NumberOfPasswordPrompts=1';
            } else {
                return new SSHResult(2, '', 'Unsupported authentication method.', false, false, 'Unsupported auth.');
            }

            $remote = $command;
            if ($host->remoteWorkspace() !== null && $host->remoteWorkspace() !== '') {
                $remote = 'cd ' . escapeshellarg($host->remoteWorkspace()) . ' && ' . $command;
            }

            $args[] = $host->target();
            $args[] = '--';
            $args[] = $remote;

            return $this->invoke($args, $env, $timeoutSeconds, $host->connectTimeoutSeconds());
        } finally {
            foreach ($tempFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    private function invoke(array $args, array $env, float $timeoutSeconds, int $connectTimeoutSeconds): SSHResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($args, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return new SSHResult(255, '', 'Failed to start ssh process.', false, true, 'ssh process start failed.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = $timeoutSeconds > 0.0
            ? microtime(true) + $timeoutSeconds
            : microtime(true) + max(30, $connectTimeoutSeconds + 20);

        while (true) {
            $status = proc_get_status($process);
            $stdout .= $this->readPipe($pipes[1]);
            $stderr .= $this->readPipe($pipes[2]);
            $stdout = $this->truncate($stdout);
            $stderr = $this->truncate($stderr);

            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(10_000);
        }

        $stdout .= $this->readPipe($pipes[1]);
        $stderr .= $this->readPipe($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $stdout = $this->truncate($stdout);
        $stderr = $this->truncate($stderr);

        if ($timedOut) {
            return new SSHResult(124, $stdout, $stderr, true, false, 'SSH command timed out.');
        }

        $connectionError = $this->looksLikeConnectionError($exit, $stderr);
        $message = $connectionError
            ? 'SSH connection failed.'
            : ($exit === 0 ? 'SSH command succeeded.' : 'SSH command failed.');

        return new SSHResult($exit, $stdout, $stderr, false, $connectionError, $message);
    }

    /** @param resource $pipe */
    private function readPipe($pipe): string
    {
        $chunk = stream_get_contents($pipe);

        return is_string($chunk) ? $chunk : '';
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_CAPTURE_BYTES) {
            return $value;
        }

        return substr($value, 0, self::MAX_CAPTURE_BYTES) . "\n...[truncated]...";
    }

    private function looksLikeConnectionError(int $exitCode, string $stderr): bool
    {
        if ($exitCode !== 255) {
            return false;
        }
        $lower = strtolower($stderr);

        return str_contains($lower, 'connection refused')
            || str_contains($lower, 'connection timed out')
            || str_contains($lower, 'could not resolve')
            || str_contains($lower, 'no route to host')
            || str_contains($lower, 'permission denied')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'network is unreachable');
    }

    private function writeTempKey(string $privateKey): string
    {
        $path = sys_get_temp_dir() . '/aep_ssh_key_' . bin2hex(random_bytes(8));
        if (file_put_contents($path, $privateKey . (str_ends_with($privateKey, "\n") ? '' : "\n")) === false) {
            throw new \RuntimeException('Unable to write temporary SSH key file.');
        }
        chmod($path, 0600);

        return $path;
    }

    /**
     * @return array{files: list<string>, env: array<string, string>}
     */
    private function writeAskPass(string $secret): array
    {
        $script = sys_get_temp_dir() . '/aep_ssh_askpass_' . bin2hex(random_bytes(8));
        $secretFile = $script . '.secret';
        if (file_put_contents($secretFile, $secret) === false) {
            throw new \RuntimeException('Unable to write temporary askpass secret.');
        }
        chmod($secretFile, 0600);

        $body = "#!/bin/sh\ncat " . escapeshellarg($secretFile) . "\n";
        if (file_put_contents($script, $body) === false) {
            @unlink($secretFile);
            throw new \RuntimeException('Unable to write temporary askpass script.');
        }
        chmod($script, 0700);

        return [
            'files' => [$script, $secretFile],
            'env' => [
                'SSH_ASKPASS' => $script,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => 'aep:0',
            ],
        ];
    }

    /** @return array<string, string> */
    private function baseEnv(): array
    {
        $env = [];
        $all = getenv();
        if (is_array($all)) {
            foreach ($all as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $env[$key] = $value;
                }
            }
        }

        return $env;
    }
}
