<?php

declare(strict_types=1);

namespace Aep\Application\CodeReview\Service;

use Aep\Application\CodeReview\Model\Patch;
use Aep\Application\MissionControl\Support\Utc;

final class ConflictDetector
{
    public function detect(Patch $patch, ?string $workspaceRoot = null): Patch
    {
        if ($workspaceRoot === null || $workspaceRoot === '') {
            return $patch;
        }

        $repo = rtrim($workspaceRoot, '/') . '/repo';
        $recorded = $patch->manifest()->headSha();
        if ($recorded === null || $recorded === '' || !is_dir($repo . '/.git') && !is_file($repo . '/.git')) {
            return $patch;
        }

        $cmd = 'git -C ' . escapeshellarg($repo) . ' rev-parse HEAD 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $patch->setStatus(Patch::STATUS_STALE, Utc::now(), 'Unable to read workspace HEAD');

            return $patch;
        }
        $head = trim(implode('', $out));
        if ($head !== '' && $head !== $recorded) {
            $patch->setStatus(Patch::STATUS_CONFLICTED, Utc::now(), 'Workspace HEAD diverged from patch headSha');
        }

        return $patch;
    }
}
