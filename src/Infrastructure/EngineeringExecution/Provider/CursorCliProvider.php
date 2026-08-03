<?php

declare(strict_types=1);

namespace Aep\Infrastructure\EngineeringExecution\Provider;

use Aep\Application\EngineeringExecution\Model\ProviderCapabilities;
use Aep\Application\EngineeringExecution\Model\ProviderHealth;
use Aep\Application\EngineeringExecution\Model\ProviderResult;
use Aep\Application\EngineeringExecution\Model\ProviderSessionRequest;
use Aep\Application\MissionControl\Support\Utc;

/**
 * Real Cursor CLI provider (reference architecture for external CLI agents).
 *
 * Invokes a configured binary via proc_open in the engineering workspace.
 * Does not synthesize workspace changes — DiffCollector continues to read
 * RESULT.diff or baseline diffs exactly as today.
 */
final class CursorCliProvider extends AbstractBufferedProvider
{
    private readonly string $binary;
    /** @var list<string> */
    private readonly array $args;
    private readonly bool $useRepoCwd;
    private readonly bool $promptViaStdin;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $id = is_string($options['id'] ?? null) ? (string) $options['id'] : 'cursor';
        $name = is_string($options['displayName'] ?? null) ? (string) $options['displayName'] : 'Cursor Agent';

        $configured = is_string($options['binary'] ?? null) ? trim((string) $options['binary']) : '';
        if ($configured === '') {
            $env = getenv('AEP_CURSOR_CLI_BINARY');
            $configured = is_string($env) && trim($env) !== '' ? trim($env) : 'cursor-agent';
        }
        $this->binary = $configured;

        $args = [];
        if (isset($options['args']) && is_array($options['args'])) {
            foreach ($options['args'] as $arg) {
                if (is_string($arg) && $arg !== '') {
                    $args[] = $arg;
                }
            }
        }
        $this->args = $args;
        $this->useRepoCwd = ($options['useRepoCwd'] ?? true) === true;
        $this->promptViaStdin = ($options['promptViaStdin'] ?? true) === true;

        $caps = isset($options['capabilities']) && is_array($options['capabilities'])
            ? ProviderCapabilities::fromArray($options['capabilities'])
            : new ProviderCapabilities(
                streaming: true,
                cancel: true,
                resume: true,
                workspaceMount: true,
                diffExport: true,
                tools: true,
                maxContextTokens: 200000,
                supportsImages: false,
                costReporting: true,
                parallelSessions: false,
            );

        parent::__construct($id, $name, $caps);
    }

    public function health(): ProviderHealth
    {
        $resolved = $this->resolveBinary();
        if ($resolved === null) {
            return new ProviderHealth(
                'unavailable',
                'Cursor CLI binary not found or not executable: ' . $this->binary,
                Utc::now()
            );
        }

        return new ProviderHealth('ok', 'Cursor CLI ready (' . $resolved . ')', Utc::now());
    }

    protected function run(ProviderSessionRequest $request): void
    {
        $sessionId = $request->sessionId();
        if ($this->isCancelled($sessionId)) {
            return;
        }

        $health = $this->health();
        if (!$health->isAvailable()) {
            $this->complete($sessionId, new ProviderResult(ProviderResult::REJECTED, $health->message()));

            return;
        }

        $binary = $this->resolveBinary();
        if ($binary === null) {
            $this->complete(
                $sessionId,
                new ProviderResult(ProviderResult::REJECTED, 'Cursor CLI binary unavailable.')
            );

            return;
        }

        $cwd = $this->resolveCwd($request->workspacePath());
        if (!is_dir($cwd)) {
            $this->complete(
                $sessionId,
                new ProviderResult(ProviderResult::FAILED, 'Working directory missing: ' . $cwd)
            );

            return;
        }

        $prompt = $this->resolvePrompt($request);
        $this->push($sessionId, 'log', 'Cursor CLI starting', [
            'binary' => $binary,
            'cwd' => $cwd,
            'promptHash' => $request->prompt()->hash(),
            'timeoutSeconds' => $request->timeoutSeconds(),
        ]);

        $command = array_merge([$binary], $this->args);
        if (!$this->promptViaStdin) {
            $command[] = $prompt;
        }

        $started = microtime(true);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            $this->complete(
                $sessionId,
                new ProviderResult(ProviderResult::FAILED, 'Unable to start Cursor CLI process.')
            );

            return;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        if ($this->promptViaStdin) {
            fwrite($pipes[0], $prompt);
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = $started + max(1, $request->timeoutSeconds());

        while (true) {
            if ($this->isCancelled($sessionId)) {
                proc_terminate($process);
                break;
            }

            $stdout .= $this->drainPipe($pipes[1], $sessionId, 'stdout');
            $stderr .= $this->drainPipe($pipes[2], $sessionId, 'stderr');

            $status = proc_get_status($process);
            if ($status['running'] === false) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                $this->push($sessionId, 'log', 'Cursor CLI timed out; terminating process');
                break;
            }

            usleep(20000);
        }

        $stdout .= $this->drainPipe($pipes[1], $sessionId, 'stdout');
        $stderr .= $this->drainPipe($pipes[2], $sessionId, 'stderr');
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $elapsed = round(microtime(true) - $started, 3);

        if ($this->isCancelled($sessionId)) {
            return;
        }

        $resultDiff = $request->workspacePath() . '/RESULT.diff';
        $diffText = is_file($resultDiff) ? (string) file_get_contents($resultDiff) : null;
        $filesChanged = $this->filesFromDiff($diffText);
        $usage = $this->estimateUsage($request, count($filesChanged));
        $meta = [
            'binary' => $binary,
            'cwd' => $cwd,
            'exitCode' => $exitCode,
            'elapsedSeconds' => $elapsed,
            'stdout' => $this->truncate($stdout),
            'stderr' => $this->truncate($stderr),
            'timedOut' => $timedOut,
        ];

        if ($timedOut) {
            $this->complete(
                $sessionId,
                new ProviderResult(
                    ProviderResult::TIMED_OUT,
                    'Cursor CLI timed out after ' . $request->timeoutSeconds() . 's',
                    $filesChanged,
                    $diffText,
                    $usage,
                    $meta
                )
            );

            return;
        }

        if ($exitCode !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : ('Cursor CLI exited with code ' . $exitCode);
            $this->complete(
                $sessionId,
                new ProviderResult(
                    ProviderResult::FAILED,
                    $this->truncate($message, 2000),
                    $filesChanged,
                    $diffText,
                    $usage,
                    $meta
                )
            );

            return;
        }

        $this->complete(
            $sessionId,
            new ProviderResult(
                ProviderResult::SUCCEEDED,
                'Cursor CLI completed ' . $request->action(),
                $filesChanged,
                $diffText,
                $usage,
                $meta
            )
        );
    }

    private function resolveCwd(string $workspacePath): string
    {
        $root = rtrim($workspacePath, "/\\");
        if ($this->useRepoCwd) {
            $repo = $root . '/repo';
            if (is_dir($repo)) {
                return $repo;
            }
        }

        return $root;
    }

    private function resolvePrompt(ProviderSessionRequest $request): string
    {
        $workspace = rtrim($request->workspacePath(), "/\\");
        foreach ([
            $workspace . '/PROMPT.md',
            $workspace . '/mounts/prompt/PROMPT.md',
        ] as $path) {
            if (is_file($path)) {
                $text = (string) file_get_contents($path);
                if (trim($text) !== '') {
                    return $text;
                }
            }
        }

        $bundle = $request->prompt();

        return trim($bundle->system() . "\n\n---\n\n" . $bundle->user());
    }

    private function resolveBinary(): ?string
    {
        $binary = trim($this->binary);
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/') || str_starts_with($binary, '.')) {
            return $this->executablePath($binary);
        }

        $pathEnv = getenv('PATH');
        $dirs = is_string($pathEnv) ? explode(PATH_SEPARATOR, $pathEnv) : [];
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $binary;
            $resolved = $this->executablePath($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function executablePath(string $path): ?string
    {
        if (!is_file($path) || !is_executable($path)) {
            return null;
        }

        $real = realpath($path);

        return is_string($real) ? $real : $path;
    }

    private function drainPipe($pipe, string $sessionId, string $stream): string
    {
        $chunk = '';
        while (!feof($pipe)) {
            $read = fread($pipe, 8192);
            if ($read === false || $read === '') {
                break;
            }
            $chunk .= $read;
            foreach (preg_split("/\r\n|\n|\r/", $read) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $this->push($sessionId, 'log', $line, ['stream' => $stream]);
            }
        }

        return $chunk;
    }

    /** @return list<string> */
    private function filesFromDiff(?string $diffText): array
    {
        if ($diffText === null || trim($diffText) === '') {
            return [];
        }
        $files = [];
        foreach (preg_split("/\r\n|\n|\r/", $diffText) ?: [] as $line) {
            if (str_starts_with($line, '+++ b/')) {
                $rel = substr($line, 6);
                if ($rel !== '' && $rel !== '/dev/null') {
                    $files[] = $rel;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function truncate(string $text, int $max = 8000): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . "\n…[truncated]";
    }
}
