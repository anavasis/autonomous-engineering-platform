<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;

/**
 * Infrastructure SSH Executor.
 *
 * Depends only on {@see RemoteCommandRunner} for transport.
 * OpenSSH is one runner implementation.
 */
final class SshExecutor implements Executor
{
    public const ID = 'ssh_remote';

    public function __construct(
        private readonly RemoteCommandRunner $runner,
        private readonly SecretResolver $secrets = new MapSecretResolver(),
        private readonly SSHAuditLog $auditLog = new SSHAuditLog(),
        private readonly int $connectRetries = 2,
    ) {
        if ($this->connectRetries < 0) {
            throw new \InvalidArgumentException('connectRetries must be >= 0.');
        }
    }

    public function id(): string
    {
        return self::ID;
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $action = $request->action();
        if ($action !== 'remote_exec' && $action !== 'implement') {
            return ExecutionResult::rejected(
                self::ID,
                'Unsupported SSH action: ' . $action,
                $request->context()
            );
        }

        try {
            $host = RemoteHost::fromContext($request->context());
            $auth = $this->resolveAuthentication($request->context());
        } catch (\InvalidArgumentException $e) {
            return ExecutionResult::rejected(self::ID, $e->getMessage(), [
                'actualExecutorId' => self::ID,
            ]);
        }

        $command = $request->contextValue('command');
        if (!is_string($command) || trim($command) === '') {
            // Allow 'implement' to use a default probe command for Mission Engine compatibility.
            if ($action === 'implement') {
                $command = 'true';
            } else {
                return ExecutionResult::rejected(self::ID, 'SSH context.command is required.', [
                    'actualExecutorId' => self::ID,
                ]);
            }
        }
        $command = trim($command);

        $timeout = 0.0;
        $timeoutValue = $request->contextValue('timeoutSeconds', 0);
        if (is_int($timeoutValue) || is_float($timeoutValue) || is_numeric($timeoutValue)) {
            $timeout = (float) $timeoutValue;
        }

        $attempts = 0;
        $maxAttempts = $this->connectRetries + 1;
        $started = microtime(true);
        $sshResult = null;

        do {
            $attempts++;
            $sshResult = $this->runner->run($host, $auth, $command, $timeout);
            if (!$sshResult->connectionError()) {
                break;
            }
        } while ($attempts < $maxAttempts);

        $durationMs = (microtime(true) - $started) * 1000.0;
        $result = $this->toExecutionResult($sshResult, $host, $attempts);
        $this->audit($request, $host, $auth, $command, $result, $sshResult, $durationMs, $attempts);

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveAuthentication(array $context): SSHAuthentication
    {
        $method = $context['authMethod'] ?? SSHAuthentication::PRIVATE_KEY;
        if (!is_string($method) || $method === '') {
            throw new \InvalidArgumentException('authMethod must be password or private_key.');
        }
        $method = strtolower(trim($method));

        $vault = isset($context['secretVault']) && is_string($context['secretVault']) ? $context['secretVault'] : null;
        $key = isset($context['secretKey']) && is_string($context['secretKey']) ? $context['secretKey'] : null;
        $secret = $this->secrets->resolve($vault, $key);
        if ($secret === null || $secret === '') {
            throw new \InvalidArgumentException('SSH secret could not be resolved.');
        }

        if ($method === SSHAuthentication::PASSWORD) {
            return SSHAuthentication::withPassword($secret);
        }
        if ($method === SSHAuthentication::PRIVATE_KEY) {
            $passphrase = null;
            $pVault = isset($context['passphraseVault']) && is_string($context['passphraseVault']) ? $context['passphraseVault'] : null;
            $pKey = isset($context['passphraseKey']) && is_string($context['passphraseKey']) ? $context['passphraseKey'] : null;
            if ($pVault !== null && $pKey !== null) {
                $passphrase = $this->secrets->resolve($pVault, $pKey);
            }

            return SSHAuthentication::withPrivateKey($secret, $passphrase);
        }

        throw new \InvalidArgumentException('authMethod must be password or private_key.');
    }

    private function toExecutionResult(SSHResult $ssh, RemoteHost $host, int $attempts): ExecutionResult
    {
        $context = [
            'actualExecutorId' => self::ID,
            'exitCode' => $ssh->exitCode(),
            'stdout' => $ssh->stdout(),
            'stderr' => $ssh->stderr(),
            'timedOut' => $ssh->timedOut(),
            'connectionError' => $ssh->connectionError(),
            'connectAttempts' => $attempts,
            'remoteHost' => $host->host(),
            'remoteUser' => $host->user(),
            'remotePort' => $host->port(),
        ];

        if ($ssh->timedOut()) {
            return ExecutionResult::failed(self::ID, 'SSH command timed out.', $context);
        }
        if ($ssh->connectionError()) {
            return ExecutionResult::failed(self::ID, 'SSH connection failed after ' . $attempts . ' attempt(s).', $context);
        }
        if ($ssh->exitCode() === 0) {
            return ExecutionResult::succeeded(
                self::ID,
                $ssh->message() !== '' ? $ssh->message() : 'SSH command succeeded.',
                $context
            );
        }

        return ExecutionResult::failed(
            self::ID,
            $ssh->message() !== '' ? $ssh->message() : 'SSH command failed with exit code ' . $ssh->exitCode() . '.',
            $context
        );
    }

    private function audit(
        ExecutionRequest $request,
        RemoteHost $host,
        SSHAuthentication $auth,
        string $command,
        ExecutionResult $result,
        SSHResult $ssh,
        float $durationMs,
        int $attempts,
    ): void {
        $runId = $request->contextValue('runId');
        $message = SSHAuditLog::redact(
            $result->message() . ' attempts=' . $attempts,
            $auth
        );
        $safeCommand = SSHAuditLog::redactCommand($command, $auth);

        $this->auditLog->append(new SSHAuditLogEntry(
            $request->occurredAtUtc(),
            $request->missionId(),
            $host->host(),
            $host->user(),
            $auth->method(),
            $safeCommand,
            $result->status(),
            $ssh->exitCode(),
            $durationMs,
            $message,
            $host->serverId(),
            is_string($runId) ? $runId : null,
        ));
    }

    public function auditLog(): SSHAuditLog
    {
        return $this->auditLog;
    }
}
