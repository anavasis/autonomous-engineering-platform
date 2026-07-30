<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Execution;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionService;
use Aep\Infrastructure\Execution\DeclarativeLocalExecutor;
use Aep\Infrastructure\Execution\SelectingExecutor;
use Aep\Infrastructure\Execution\Ssh\MapSecretResolver;
use Aep\Infrastructure\Execution\Ssh\RemoteCommandRunner;
use Aep\Infrastructure\Execution\Ssh\RemoteHost;
use Aep\Infrastructure\Execution\Ssh\SSHAuditLog;
use Aep\Infrastructure\Execution\Ssh\SSHAuthentication;
use Aep\Infrastructure\Execution\Ssh\SSHResult;
use Aep\Infrastructure\Execution\Ssh\SshExecutor;
use Tests\Support\Assert;

/**
 * ORCH-R10 SshExecutor / SelectingExecutor infrastructure tests.
 */
final class SshExecutorTest
{
    public function test_password_auth(): void
    {
        $fake = new FakeRemoteCommandRunner(
            static fn (RemoteHost $h, SSHAuthentication $a, string $c, float $t): SSHResult => new SSHResult(0, "ok\n", '', false, false, 'ok')
        );
        $executor = $this->executor($fake, [
            'vault/ssh-pass' => 's3cret-pass',
        ]);

        $result = $executor->execute($this->request([
            'authMethod' => 'password',
            'secretVault' => 'vault',
            'secretKey' => 'ssh-pass',
            'command' => 'echo ok',
        ]));

        Assert::true($result->isSucceeded());
        Assert::true($fake->lastAuth !== null && $fake->lastAuth->isPassword());
        Assert::same('s3cret-pass', $fake->lastAuth->password());
    }

    public function test_key_auth(): void
    {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----";
        $fake = new FakeRemoteCommandRunner(
            static fn (RemoteHost $h, SSHAuthentication $a, string $c, float $t): SSHResult => new SSHResult(0, '', '', false, false, 'ok')
        );
        $executor = $this->executor($fake, [
            'vault/ssh-key' => $key,
        ]);

        $result = $executor->execute($this->request([
            'authMethod' => 'private_key',
            'secretVault' => 'vault',
            'secretKey' => 'ssh-key',
            'command' => 'uname -s',
        ]));

        Assert::true($result->isSucceeded());
        Assert::true($fake->lastAuth !== null && $fake->lastAuth->isPrivateKey());
        Assert::same($key, $fake->lastAuth->privateKey());
    }

    public function test_timeout(): void
    {
        $fake = new FakeRemoteCommandRunner(
            static fn (RemoteHost $h, SSHAuthentication $a, string $c, float $t): SSHResult => new SSHResult(124, '', 'timeout', true, false, 'timed out')
        );
        $executor = $this->executor($fake, ['vault/k' => 'x']);

        $result = $executor->execute($this->request([
            'authMethod' => 'password',
            'secretVault' => 'vault',
            'secretKey' => 'k',
            'command' => 'sleep 30',
            'timeoutSeconds' => 1,
        ]));

        Assert::true($result->isFailed());
        Assert::same(true, $result->context()['timedOut'] ?? null);
        Assert::same(124, $result->context()['exitCode'] ?? null);
    }

    public function test_connect_retry(): void
    {
        $attempts = 0;
        $fake = new FakeRemoteCommandRunner(
            static function (
                RemoteHost $h,
                SSHAuthentication $a,
                string $c,
                float $t
            ) use (&$attempts): SSHResult {
                $attempts++;
                if ($attempts < 2) {
                    return new SSHResult(255, '', 'Connection refused', false, true, 'conn');
                }

                return new SSHResult(0, "recovered\n", '', false, false, 'ok');
            }
        );
        $executor = new SshExecutor(
            $fake,
            new MapSecretResolver(['vault/k' => 'x']),
            new SSHAuditLog(),
            2
        );

        $result = $executor->execute($this->request([
            'authMethod' => 'password',
            'secretVault' => 'vault',
            'secretKey' => 'k',
            'command' => 'echo recovered',
        ]));

        Assert::true($result->isSucceeded(), $result->message());
        Assert::same(2, $fake->calls);
        Assert::same(2, $result->context()['connectAttempts'] ?? null);
    }

    public function test_stdout_stderr_exit_code(): void
    {
        $fake = new FakeRemoteCommandRunner(
            static fn (RemoteHost $h, SSHAuthentication $a, string $c, float $t): SSHResult => new SSHResult(
                7,
                "out-line\n",
                "err-line\n",
                false,
                false,
                'failed'
            )
        );
        $executor = $this->executor($fake, ['vault/k' => 'x']);

        $result = $executor->execute($this->request([
            'authMethod' => 'password',
            'secretVault' => 'vault',
            'secretKey' => 'k',
            'command' => 'false',
        ]));

        Assert::true($result->isFailed());
        Assert::same(7, $result->context()['exitCode'] ?? null);
        Assert::same("out-line\n", $result->context()['stdout'] ?? null);
        Assert::same("err-line\n", $result->context()['stderr'] ?? null);
    }

    public function test_audit_log_and_redaction(): void
    {
        $password = 'super-secret-password-value';
        $fake = new FakeRemoteCommandRunner(
            static fn (RemoteHost $h, SSHAuthentication $a, string $c, float $t): SSHResult => new SSHResult(
                0,
                'echoed ' . $password,
                '',
                false,
                false,
                'ok containing ' . $password
            )
        );
        $audit = new SSHAuditLog();
        $executor = new SshExecutor(
            $fake,
            new MapSecretResolver(['vault/k' => $password]),
            $audit,
            0
        );

        $result = $executor->execute($this->request([
            'authMethod' => 'password',
            'secretVault' => 'vault',
            'secretKey' => 'k',
            'command' => 'echo ' . $password,
            'runId' => 'run_ssh_1',
        ]));

        Assert::true($result->isSucceeded());
        Assert::same(1, count($audit->entries()));
        $row = $audit->entries()[0]->toArray();
        Assert::same('password', $row['authMethod']);
        Assert::same('host.example.test', $row['host']);
        Assert::same('deploy', $row['user']);
        Assert::true(!str_contains((string) $row['message'], $password));
        Assert::true(!str_contains((string) $row['command'], $password));
        Assert::true(str_contains((string) $row['message'], '***'));
    }

    public function test_missing_secret_is_rejected(): void
    {
        $fake = new FakeRemoteCommandRunner(
            static fn (): SSHResult => new SSHResult(0, '', '')
        );
        $executor = $this->executor($fake, []);
        $result = $executor->execute($this->request([
            'authMethod' => 'password',
            'secretVault' => 'vault',
            'secretKey' => 'missing',
            'command' => 'true',
        ]));
        Assert::true($result->isRejected());
        Assert::same(0, $fake->calls);
    }

    public function test_selecting_executor_routes_ssh_and_local(): void
    {
        $fake = new FakeRemoteCommandRunner(
            static fn (RemoteHost $h, SSHAuthentication $a, string $c, float $t): SSHResult => new SSHResult(0, "ssh\n", '')
        );
        $ssh = $this->executor($fake, ['vault/k' => 'x']);
        $local = new DeclarativeLocalExecutor();
        $selecting = new SelectingExecutor([
            'local' => $local,
            'ssh' => $ssh,
        ], 'local');

        $service = new ExecutionService($selecting);

        $sshResult = $service->execute(new ExecutionRequest(
            'msn_sel_1',
            'remote_exec',
            '2026-07-30T12:00:00Z',
            [
                'executor' => 'ssh',
                'host' => 'host.example.test',
                'user' => 'deploy',
                'authMethod' => 'password',
                'secretVault' => 'vault',
                'secretKey' => 'k',
                'command' => 'echo ssh',
            ]
        ));
        Assert::true($sshResult->isSucceeded());
        Assert::same(SelectingExecutor::ID, $sshResult->executorId());
        Assert::same(SshExecutor::ID, $sshResult->context()['actualExecutorId'] ?? null);

        $localResult = $service->execute(new ExecutionRequest(
            'msn_sel_2',
            'implement',
            '2026-07-30T12:00:00Z',
            ['executor' => 'local']
        ));
        Assert::true($localResult->isSucceeded());
        Assert::same(SelectingExecutor::ID, $localResult->executorId());
        Assert::same(DeclarativeLocalExecutor::ID, $localResult->context()['actualExecutorId'] ?? null);
    }

    /**
     * @param array<string, string> $secrets
     */
    private function executor(FakeRemoteCommandRunner $fake, array $secrets): SshExecutor
    {
        return new SshExecutor(
            $fake,
            new MapSecretResolver($secrets),
            new SSHAuditLog(),
            2
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function request(array $context): ExecutionRequest
    {
        return new ExecutionRequest(
            'msn_ssh_1',
            'remote_exec',
            '2026-07-30T12:00:00Z',
            array_merge([
                'host' => 'host.example.test',
                'user' => 'deploy',
                'port' => 22,
            ], $context)
        );
    }
}

/**
 * Test double RemoteCommandRunner.
 */
final class FakeRemoteCommandRunner implements RemoteCommandRunner
{
    public int $calls = 0;
    public ?SSHAuthentication $lastAuth = null;
    public ?string $lastCommand = null;
    public float $lastTimeout = 0.0;

    /** @param callable(RemoteHost, SSHAuthentication, string, float): SSHResult $handler */
    public function __construct(
        private $handler
    ) {
    }

    public function run(
        RemoteHost $host,
        SSHAuthentication $authentication,
        string $command,
        float $timeoutSeconds = 0.0,
    ): SSHResult {
        $this->calls++;
        $this->lastAuth = $authentication;
        $this->lastCommand = $command;
        $this->lastTimeout = $timeoutSeconds;

        return ($this->handler)($host, $authentication, $command, $timeoutSeconds);
    }
}
