<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\ChangeManifest;
use Aep\Application\CodeReview\Model\CheckRun;
use Aep\Application\MissionControl\Support\Utc;

final class StaticAnalysisRunner
{
    public function run(ChangeManifest $manifest, ?string $workspaceRoot = null): CheckRun
    {
        $started = microtime(true);
        $findings = [];

        foreach ($manifest->files() as $file) {
            $path = $file['path'];
            if (str_starts_with($path, 'src/Domain/') || str_starts_with($path, 'src/Application/MissionEngine/')) {
                $findings[] = [
                    'severity' => 'warning',
                    'path' => $path,
                    'message' => 'Touches sensitive platform core path',
                ];
            }
            if ($workspaceRoot !== null && str_ends_with($path, '.php')) {
                $full = rtrim($workspaceRoot, '/') . '/repo/' . $path;
                if (!is_file($full)) {
                    $full = rtrim($workspaceRoot, '/') . '/context/' . $path;
                }
                if (is_file($full)) {
                    $lint = $this->phpLint($full);
                    if ($lint !== null) {
                        $findings[] = ['severity' => 'error', 'path' => $path, 'message' => $lint];
                    }
                }
            }
        }

        $hasError = false;
        foreach ($findings as $f) {
            if ($f['severity'] === 'error') {
                $hasError = true;
                break;
            }
        }

        return new CheckRun(
            'chk_static_' . bin2hex(random_bytes(4)),
            'static',
            $hasError ? 'failed' : 'passed',
            $hasError ? 'Static analysis failed' : 'Static analysis passed',
            $findings,
            microtime(true) - $started,
            Utc::now(),
            'sha256:' . hash('sha256', json_encode($findings, JSON_THROW_ON_ERROR)),
        );
    }

    private function phpLint(string $file): ?string
    {
        $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            return trim(implode("\n", $out));
        }

        return null;
    }
}
