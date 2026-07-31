<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Git;

/**
 * Infrastructure-only workspace layout for a project Git checkout.
 *
 * workspace/projects/{projectId}/repository|logs|temp|metadata.json
 */
final class ProjectWorkspace
{
    public function __construct(
        private readonly string $rootDir,
        private readonly string $projectId,
    ) {
        if ($this->rootDir === '') {
            throw new \InvalidArgumentException('Workspace root directory is required.');
        }
        if ($this->projectId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $this->projectId)) {
            throw new \InvalidArgumentException('Invalid projectId for workspace path.');
        }
    }

    public function projectDir(): string
    {
        return $this->rootDir . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . $this->projectId;
    }

    public function repositoryDir(): string
    {
        return $this->projectDir() . DIRECTORY_SEPARATOR . 'repository';
    }

    public function logsDir(): string
    {
        return $this->projectDir() . DIRECTORY_SEPARATOR . 'logs';
    }

    public function tempDir(): string
    {
        return $this->projectDir() . DIRECTORY_SEPARATOR . 'temp';
    }

    public function metadataFile(): string
    {
        return $this->projectDir() . DIRECTORY_SEPARATOR . 'metadata.json';
    }

    public function operationLogFile(): string
    {
        return $this->logsDir() . DIRECTORY_SEPARATOR . 'operations.jsonl';
    }

    public function ensureLayout(): void
    {
        foreach ([$this->projectDir(), $this->repositoryDir(), $this->logsDir(), $this->tempDir()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create workspace directory: ' . $dir);
            }
        }

        if (!is_file($this->metadataFile())) {
            $initial = [
                'currentBranch' => null,
                'currentCommitSha' => null,
                'remoteUrl' => null,
                'lastFetchAt' => null,
                'repositoryHealth' => 'unknown',
            ];
            $this->writeJson($this->metadataFile(), $initial);
        }
    }

    public function repositoryExists(): bool
    {
        return is_dir($this->repositoryDir() . DIRECTORY_SEPARATOR . '.git')
            || is_file($this->repositoryDir() . DIRECTORY_SEPARATOR . 'HEAD');
    }

    /** @param array<string, mixed> $data */
    public function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode JSON for ' . $path);
        }
        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('Unable to write ' . $path);
        }
    }

    /** @return array<string, mixed>|null */
    public function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
