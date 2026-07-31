<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionExecution\Model\MissionIntent;

final class MissionValidator
{
    /**
     * @param list<array<string, mixed>> $projects
     * @return array{ok: bool, reason: ?string}
     */
    public function validate(MissionIntent $intent, array $projects): array
    {
        if (trim($intent->objective()) === '' && trim($intent->rawText()) === '') {
            return ['ok' => false, 'reason' => 'Mission text is empty.'];
        }
        if (trim($intent->objective()) === '') {
            return ['ok' => false, 'reason' => 'Unable to derive a mission objective.'];
        }

        foreach ($intent->forbiddenAreas() as $forbidden) {
            foreach ($intent->allowedPaths() as $path) {
                if ($forbidden !== '' && str_contains($path, $forbidden)) {
                    return ['ok' => false, 'reason' => 'Allowed path conflicts with forbidden area: ' . $forbidden];
                }
            }
        }

        if (count($projects) > 1 && ($intent->projectId() === null || $intent->projectId() === '')) {
            return ['ok' => false, 'reason' => 'Project is required when multiple projects exist.'];
        }

        $provider = $intent->provider();
        $repository = $intent->repository();
        if (($provider === null || $provider === '') || ($repository === null || $repository === '')) {
            // Allow if single bound project will be enriched later — still fail if nothing available.
            $hasBound = false;
            foreach ($projects as $p) {
                if (($p['repositoryStatus'] ?? '') === 'bound') {
                    if ($intent->projectId() === null || ($p['id'] ?? null) === $intent->projectId()) {
                        $hasBound = true;
                        break;
                    }
                }
            }
            if (!$hasBound && count($projects) === 0) {
                // No projects: require explicit target
                return ['ok' => false, 'reason' => 'Target repository is required (provider/repository).'];
            }
            if (!$hasBound && $intent->projectId() !== null) {
                return ['ok' => false, 'reason' => 'Selected project has no repository binding and no target was provided.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }
}
