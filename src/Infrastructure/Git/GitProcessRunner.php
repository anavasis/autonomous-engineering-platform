<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Git;

/**
 * Runs git CLI commands via proc_open.
 *
 * Never logs argv that may contain credentials; callers must sanitize before recording.
 */
final class GitProcessRunner
{
    public function __construct(
        private readonly string $gitBinary = 'git',
    ) {
    }

    /**
     * @param list<string> $args Git arguments (without the git binary)
     * @param array<string, string> $env Extra environment variables
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(array $args, ?string $cwd = null, array $env = []): array
    {
        $command = array_merge([$this->gitBinary], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envVars = $this->baseEnv();
        foreach ($env as $key => $value) {
            $envVars[$key] = $value;
        }

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $cwd,
            $envVars,
        );

        if (!is_resource($process)) {
            return [
                'exitCode' => 127,
                'stdout' => '',
                'stderr' => 'Failed to start git process.',
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
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

        // Prevent interactive credential prompts.
        $env['GIT_TERMINAL_PROMPT'] = '0';

        return $env;
    }
}
