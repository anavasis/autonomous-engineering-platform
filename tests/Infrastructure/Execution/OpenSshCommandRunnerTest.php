<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Execution;

use Aep\Infrastructure\Execution\Ssh\OpenSshCommandRunner;
use Aep\Infrastructure\Execution\Ssh\RemoteHost;
use Aep\Infrastructure\Execution\Ssh\SSHAuthentication;
use Tests\Support\Assert;

/**
 * OpenSSH CLI runner tests using a stub ssh binary (no network).
 */
final class OpenSshCommandRunnerTest
{
    public function test_stdout_stderr_exit_code_via_stub(): void
    {
        $stub = $this->writeStubSsh(<<<'SH'
#!/bin/sh
echo "STDOUT_OK"
echo "STDERR_OK" >&2
exit 3
SH);
        try {
            $runner = new OpenSshCommandRunner($stub);
            $result = $runner->run(
                new RemoteHost('127.0.0.1', 'deploy'),
                SSHAuthentication::withPassword('pw'),
                'remote-command',
                5.0
            );
            Assert::same(3, $result->exitCode());
            Assert::true(str_contains($result->stdout(), 'STDOUT_OK'));
            Assert::true(str_contains($result->stderr(), 'STDERR_OK'));
            Assert::true(!$result->timedOut());
        } finally {
            @unlink($stub);
        }
    }

    public function test_timeout_via_stub(): void
    {
        $stub = $this->writeStubSsh(<<<'SH'
#!/bin/sh
sleep 2
echo late
exit 0
SH);
        try {
            $runner = new OpenSshCommandRunner($stub);
            $result = $runner->run(
                new RemoteHost('127.0.0.1', 'deploy', 22, 1),
                SSHAuthentication::withPrivateKey("-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----"),
                'sleep-remote',
                0.05
            );
            Assert::true($result->timedOut());
            Assert::same(124, $result->exitCode());
        } finally {
            @unlink($stub);
        }
    }

    public function test_connection_error_via_stub(): void
    {
        $stub = $this->writeStubSsh(<<<'SH'
#!/bin/sh
echo "ssh: connect to host 127.0.0.1 port 22: Connection refused" >&2
exit 255
SH);
        try {
            $runner = new OpenSshCommandRunner($stub);
            $result = $runner->run(
                new RemoteHost('127.0.0.1', 'deploy'),
                SSHAuthentication::withPassword('pw'),
                'true',
                2.0
            );
            Assert::true($result->connectionError());
            Assert::same(255, $result->exitCode());
        } finally {
            @unlink($stub);
        }
    }

    public function test_password_askpass_env_and_key_file(): void
    {
        $stub = $this->writeStubSsh(<<<'SH'
#!/bin/sh
# Print whether ASKPASS is set and whether -i key exists among args
echo "ASKPASS=${SSH_ASKPASS:-}"
for a in "$@"; do
  case "$a" in
    /tmp/aep_ssh_key_*|/var/tmp/aep_ssh_key_*|*/aep_ssh_key_*)
      if [ -f "$a" ]; then
        mode=$(stat -c '%a' "$a" 2>/dev/null || stat -f '%OLp' "$a")
        echo "KEYFILE=$a MODE=$mode"
      fi
      ;;
  esac
done
exit 0
SH);
        try {
            $runner = new OpenSshCommandRunner($stub);

            $pass = $runner->run(
                new RemoteHost('127.0.0.1', 'deploy'),
                SSHAuthentication::withPassword('pw-value'),
                'true',
                2.0
            );
            Assert::same(0, $pass->exitCode());
            Assert::true(str_contains($pass->stdout(), 'ASKPASS='));
            Assert::true(str_contains($pass->stdout(), 'aep_ssh_askpass_'));

            $key = $runner->run(
                new RemoteHost('127.0.0.1', 'deploy'),
                SSHAuthentication::withPrivateKey("-----BEGIN OPENSSH PRIVATE KEY-----\nXYZ\n-----END OPENSSH PRIVATE KEY-----"),
                'true',
                2.0
            );
            Assert::same(0, $key->exitCode());
            Assert::true(str_contains($key->stdout(), 'KEYFILE='));
            Assert::true(str_contains($key->stdout(), 'MODE=600'));
        } finally {
            @unlink($stub);
        }
    }

    private function writeStubSsh(string $script): string
    {
        $path = sys_get_temp_dir() . '/aep_stub_ssh_' . bin2hex(random_bytes(6));
        if (file_put_contents($path, $script) === false) {
            throw new \RuntimeException('Unable to write stub ssh.');
        }
        chmod($path, 0755);

        return $path;
    }
}
