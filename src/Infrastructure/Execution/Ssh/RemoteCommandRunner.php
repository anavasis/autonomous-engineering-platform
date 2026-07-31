<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Execution\Ssh;

/**
 * Transport-agnostic remote command runner.
 *
 * OpenSSH is one implementation; future runners (e.g. Docker) can replace it.
 */
interface RemoteCommandRunner
{
    /**
     * Execute one remote command using exactly one connection for this call.
     *
     * @param float $timeoutSeconds Overall command wall-clock timeout (0 = no limit beyond connect timeout)
     */
    public function run(
        RemoteHost $host,
        SSHAuthentication $authentication,
        string $command,
        float $timeoutSeconds = 0.0,
    ): SSHResult;
}
