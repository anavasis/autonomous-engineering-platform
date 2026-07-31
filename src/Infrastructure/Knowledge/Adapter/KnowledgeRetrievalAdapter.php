<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Knowledge\Adapter;

use Aep\Application\Execution\ExecutionRequest;
use Aep\Application\Execution\ExecutionResult;
use Aep\Application\Execution\Executor;
use Aep\Application\Knowledge\Model\RetrievalQuery;
use Aep\Application\Knowledge\Port\KnowledgeSettingsStore;
use Aep\Application\Knowledge\Service\KnowledgeRetrievalService;

/**
 * Injects ranked engineering knowledge into ExecutionRequest before provider execution.
 * Does not modify Mission Engine or EngineeringExecutionProvider contracts.
 */
final class KnowledgeRetrievalAdapter implements Executor
{
    public function __construct(
        private readonly Executor $inner,
        private readonly KnowledgeRetrievalService $retrieval,
        private readonly KnowledgeSettingsStore $settings,
    ) {
    }

    public function id(): string
    {
        return $this->inner->id();
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $settings = $this->settings->get();
        if (($settings['autoRetrieveEnabled'] ?? true) !== true) {
            return $this->inner->execute($request);
        }

        try {
            $objective = '';
            foreach (['objective', 'summary', 'planSummary'] as $key) {
                $v = $request->contextValue($key);
                if (is_string($v) && $v !== '') {
                    $objective = $v;
                    break;
                }
            }
            if ($objective === '') {
                $objective = $request->action();
            }
            $projectId = is_string($request->contextValue('projectId'))
                ? (string) $request->contextValue('projectId')
                : null;
            $pack = $this->retrieval->retrieve(new RetrievalQuery(
                $objective,
                $projectId,
                $request->missionId(),
                [],
                [],
                [],
                [],
                is_int($settings['maxNotes'] ?? null) ? (int) $settings['maxNotes'] : 12,
                'pre_execution',
            ));
            $existing = $request->contextValue('engineeringKnowledge');
            $notes = $pack->notes();
            if (is_array($existing)) {
                foreach ($existing as $item) {
                    if (is_string($item) && $item !== '') {
                        $notes[] = $item;
                    }
                }
            }
            $context = $request->context();
            $context['engineeringKnowledge'] = array_values(array_unique($notes));
            $context['knowledgeRetrievalFingerprint'] = $pack->toArray()['fingerprint'] ?? null;
            $request = new ExecutionRequest(
                $request->missionId(),
                $request->action(),
                $request->occurredAtUtc(),
                $context,
            );
        } catch (\Throwable) {
            // best-effort: never fail execution due to retrieval
        }

        return $this->inner->execute($request);
    }
}
