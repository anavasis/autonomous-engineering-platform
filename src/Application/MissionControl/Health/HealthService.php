<?php

declare(strict_types=1);

namespace Aep\Application\MissionControl\Health;

final class HealthService
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly string $missionsDir,
        private readonly string $projectsDir,
        private readonly string $runsDir,
        private readonly string $artifactsDir,
        private readonly string $version = '0.1.0',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(): array
    {
        return [
            'status' => $this->overall(),
            'version' => $this->version,
            'api' => 'ok',
            'persistence' => $this->dirStatus($this->dataRoot),
            'missions' => $this->dirStatus($this->missionsDir),
            'projects' => $this->dirStatus($this->projectsDir),
            'runs' => $this->dirStatus($this->runsDir),
            'artifacts' => $this->dirStatus($this->artifactsDir),
            'checkedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function overall(): string
    {
        foreach ([$this->dataRoot, $this->missionsDir, $this->projectsDir, $this->runsDir, $this->artifactsDir] as $dir) {
            if ($this->dirStatus($dir) !== 'ok') {
                return 'degraded';
            }
        }

        return 'ok';
    }

    private function dirStatus(string $dir): string
    {
        if ($dir === '') {
            return 'misconfigured';
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'unavailable';
        }
        if (!is_writable($dir)) {
            return 'read_only';
        }

        return 'ok';
    }
}
