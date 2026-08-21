<?php

declare(strict_types=1);

namespace Aep\Application\ExecutionRuntime\Handler;

use Aep\Application\ExecutionRuntime\Model\RuntimeJob;
use Aep\Application\ExecutionRuntime\Port\JobHandler;
use Aep\Application\ExecutionRuntime\Service\JobExecutionContext;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionEngine\MissionEngine;
use Aep\Application\MissionEngine\MissionEngineRequest;

/**
 * First Runtime job type: mission-execution.
 * Invokes MissionEngine unchanged (start / resume only).
 */
final class MissionExecutionJobHandler implements JobHandler
{
    public const TYPE = 'mission-execution';

    public function __construct(
        private readonly MissionEngine $engine,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function execute(RuntimeJob $job, JobExecutionContext $context): array
    {
        if ($context->isCancelRequested()) {
            throw new \RuntimeException('Job cancelled before execution: ' . ($job->cancelReason() ?? 'cancelled'));
        }

        $payload = $job->payload();
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : 'start';
        $context->heartbeat(Utc::now());

        if ($action === 'resume') {
            $runId = is_string($payload['runId'] ?? null) ? $payload['runId'] : '';
            if ($runId === '') {
                throw new \InvalidArgumentException('resume requires runId.');
            }
            $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $at = is_string($payload['occurredAtUtc'] ?? null) ? $payload['occurredAtUtc'] : Utc::now();
            if ($context->isCancelRequested()) {
                $this->engine->cancel($runId, $job->cancelReason() ?? 'Cancelled by Runtime.', $at);

                return [
                    'action' => 'resume',
                    'runId' => $runId,
                    'engineState' => 'suspended',
                    'message' => 'Cancelled before resume.',
                    'cancelled' => true,
                ];
            }
            $result = $this->engine->resume($runId, $attributes, $at);
            if ($context->isCancelRequested()) {
                $this->engine->cancel($runId, $job->cancelReason() ?? 'Cancelled by Runtime.', Utc::now());
            }

            return [
                'action' => 'resume',
                'runId' => $result->runId(),
                'missionId' => $result->missionId(),
                'engineState' => $result->engineState()->toString(),
                'message' => $result->message(),
                'progressPercent' => $result->progressPercent(),
            ];
        }

        $runId = is_string($payload['runId'] ?? null) ? $payload['runId'] : '';
        $missionId = is_string($payload['missionId'] ?? null) ? $payload['missionId'] : '';
        if ($runId === '' || $missionId === '') {
            throw new \InvalidArgumentException('start requires runId and missionId.');
        }
        $actorType = is_string($payload['actorType'] ?? null) ? $payload['actorType'] : 'system';
        $actorId = is_string($payload['actorId'] ?? null) ? $payload['actorId'] : 'runtime';
        $projectId = is_string($payload['projectId'] ?? null) ? $payload['projectId'] : null;
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $at = is_string($payload['occurredAtUtc'] ?? null) ? $payload['occurredAtUtc'] : Utc::now();

        if ($context->isCancelRequested()) {
            return [
                'action' => 'start',
                'runId' => $runId,
                'missionId' => $missionId,
                'engineState' => 'cancelled',
                'message' => 'Cancelled before start.',
                'cancelled' => true,
            ];
        }

        $result = $this->engine->start(new MissionEngineRequest(
            $runId,
            $missionId,
            $at,
            $actorType,
            $actorId,
            $attributes,
            $projectId,
        ));

        if ($context->isCancelRequested()) {
            $this->engine->cancel($runId, $job->cancelReason() ?? 'Cancelled by Runtime.', Utc::now());
        }

        return [
            'action' => 'start',
            'runId' => $result->runId(),
            'missionId' => $result->missionId(),
            'engineState' => $result->engineState()->toString(),
            'message' => $result->message(),
            'progressPercent' => $result->progressPercent(),
        ];
    }
}
