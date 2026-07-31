<?php

declare(strict_types=1);

namespace Aep\Application\EngineeringExecution\Service;

use Aep\Application\Artifact\ArtifactKind;
use Aep\Application\Artifact\ArtifactService;
use Aep\Application\EngineeringExecution\Model\ExecutionSession;
use Aep\Application\EngineeringExecution\Model\PromptBundle;
use Aep\Application\MissionControl\Support\Utc;

final class ArtifactCapture
{
    public function __construct(
        private readonly ArtifactService $artifacts,
    ) {
    }

    /**
     * @return array<string, string> artifactId => kind
     */
    public function capture(
        ExecutionSession $session,
        PromptBundle $prompt,
        string $diff,
        string $logText,
        array $usage,
        ?array $checkpoint,
    ): array {
        $at = Utc::now();
        $missionId = $session->missionId();
        $runId = $session->runId() !== '' ? $session->runId() : 'run_exec';
        $map = [];

        $this->artifacts->ensureWorkspace($missionId, $runId, $at);
        $this->put($missionId, $runId, 'report.execution.prompt', ArtifactKind::REPORT, 'prompt-bundle.json', json_encode($prompt->toArray(), JSON_THROW_ON_ERROR), $at, $map);
        $this->put($missionId, $runId, 'report.execution.diff', ArtifactKind::REPORT, 'execution.diff', $diff, $at, $map);
        $this->put($missionId, $runId, 'log.execution.provider', ArtifactKind::LOG, 'provider.log', $logText, $at, $map);
        $this->put($missionId, $runId, 'report.execution.usage', ArtifactKind::REPORT, 'usage.json', json_encode($usage, JSON_THROW_ON_ERROR), $at, $map);
        if ($checkpoint !== null) {
            $this->put(
                $missionId,
                $runId,
                'checkpoint.execution.session',
                ArtifactKind::CHECKPOINT,
                'execution-checkpoint.json',
                json_encode($checkpoint, JSON_THROW_ON_ERROR),
                $at,
                $map
            );
        }

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private function put(
        string $missionId,
        string $runId,
        string $artifactId,
        string $kind,
        string $name,
        string $contents,
        string $at,
        array &$map,
    ): void {
        $this->artifacts->put($missionId, $runId, $artifactId, $kind, $name, $contents, $at, true, 'application/json');
        $map[$artifactId] = $kind;
    }
}
