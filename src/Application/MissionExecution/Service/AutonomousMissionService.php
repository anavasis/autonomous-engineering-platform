<?php

declare(strict_types=1);

namespace Aep\Application\MissionExecution\Service;

use Aep\Application\MissionControl\Auth\User;
use Aep\Application\MissionControl\Query\ProjectQueryService;
use Aep\Application\MissionControl\Support\Utc;
use Aep\Application\MissionExecution\Model\Conversation;
use Aep\Application\MissionExecution\Model\ConversationTurn;
use Aep\Application\MissionExecution\Model\ExecutionPlan;
use Aep\Application\MissionExecution\Model\MissionIntake;
use Aep\Application\MissionExecution\Port\ConversationRepository;
use Aep\Application\MissionExecution\Port\IntakeRepository;
use Aep\Application\MissionExecution\Port\PlanRepository;
use Aep\Application\MissionExecution\Port\ProjectMemoryRepository;
use Aep\Application\MissionExecution\Port\PromptUnderstandingPort;

/**
 * Orchestrates intake → understand → clarify → plan → confirm → launch.
 */
final class AutonomousMissionService
{
    public function __construct(
        private readonly IntakeRepository $intakes,
        private readonly ConversationRepository $conversations,
        private readonly PlanRepository $plans,
        private readonly ProjectMemoryRepository $memory,
        private readonly PromptUnderstandingPort $understanding,
        private readonly ClarificationEngine $clarification,
        private readonly MissionValidator $validator,
        private readonly MissionPlanner $planner,
        private readonly LaunchFacade $launch,
        private readonly EngineeringMemoryService $engineeringMemory,
        private readonly ProjectQueryService $projects,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function intake(
        User $actor,
        string $text,
        ?string $projectIdHint = null,
        ?string $clientRequestId = null,
    ): array {
        if ($clientRequestId !== null && $clientRequestId !== '') {
            $existing = $this->intakes->findByClientRequestId($clientRequestId);
            if ($existing !== null) {
                return $this->presentIntake($existing);
            }
        }

        $at = Utc::now();
        $conversationId = 'conv_' . bin2hex(random_bytes(6));
        $intakeId = 'intake_' . bin2hex(random_bytes(6));
        $conversation = new Conversation($conversationId, [], null, $intakeId);
        $conversation->append(new ConversationTurn('user', $text, $at));

        $intake = new MissionIntake(
            $intakeId,
            $actor->id(),
            $text,
            MissionIntake::STATUS_RECEIVED,
            $conversationId,
            $at,
            $at,
            null,
            [],
            null,
            $clientRequestId,
            $projectIdHint
        );

        $this->processUnderstanding($intake, $conversation, $projectIdHint);
        $this->conversations->save($conversation);
        $this->intakes->save($intake);

        return $this->presentIntake($intake);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    public function clarify(User $actor, string $intakeId, array $answers): array
    {
        unset($actor);
        $intake = $this->requireIntake($intakeId);
        if ($intake->status() === MissionIntake::STATUS_LAUNCHED) {
            throw new \InvalidArgumentException('Intake already launched.');
        }
        if ($intake->intent() === null) {
            throw new \InvalidArgumentException('Intake has no intent to clarify.');
        }

        $at = Utc::now();
        $intent = $intake->intent()->mergeAnswers($answers);
        $intake->setIntent($intent, $at);

        $conversation = $this->conversations->find($intake->conversationId())
            ?? new Conversation($intake->conversationId(), [], null, $intake->id());
        $conversation->append(new ConversationTurn(
            'user',
            'Clarification answers: ' . json_encode($answers, JSON_THROW_ON_ERROR),
            $at,
            $answers
        ));

        $this->processUnderstanding($intake, $conversation, $intake->projectIdHint(), false);
        $this->conversations->save($conversation);
        $this->intakes->save($intake);

        return $this->presentIntake($intake);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $intakeId): array
    {
        $intake = $this->requireIntake($intakeId);
        if ($intake->status() !== MissionIntake::STATUS_READY && $intake->status() !== MissionIntake::STATUS_LAUNCHED) {
            throw new \InvalidArgumentException('Plan preview requires a ready intake. Status: ' . $intake->status());
        }
        if ($intake->intent() === null) {
            throw new \InvalidArgumentException('Intake has no intent.');
        }

        $existing = $this->plans->findByIntakeId($intakeId);
        if ($existing !== null && $intake->status() === MissionIntake::STATUS_LAUNCHED) {
            return $existing->toPreviewArray();
        }

        $plan = $this->planner->plan($intakeId, $intake->intent());
        $this->plans->save($plan);
        $intake->markReady(Utc::now()); // keep ready; plan id stored at launch
        // Store plan id on a soft field via save of plan only
        $this->intakes->save($intake);

        return $plan->toPreviewArray();
    }

    /**
     * Explicit operator confirmation required.
     *
     * @return array<string, mixed>
     */
    public function confirmAndLaunch(User $actor, string $intakeId, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('Explicit confirmation is required before launch.');
        }
        $intake = $this->requireIntake($intakeId);
        if ($intake->status() === MissionIntake::STATUS_LAUNCHED) {
            return [
                'missionId' => $intake->missionId(),
                'runId' => $intake->runId(),
                'planId' => $intake->planId(),
                'status' => 'launched',
            ];
        }
        if ($intake->status() !== MissionIntake::STATUS_READY || $intake->intent() === null) {
            throw new \InvalidArgumentException('Intake is not ready for launch.');
        }

        $plan = $this->plans->findByIntakeId($intakeId) ?? $this->planner->plan($intakeId, $intake->intent());
        $this->plans->save($plan);

        $result = $this->launch->launch($actor, $intake, $plan);
        $at = Utc::now();
        $intake->markLaunched($result['missionId'], $result['runId'], $plan->id(), $at);
        $this->intakes->save($intake);

        $planData = $plan->toArray();
        $planData['launchAttributes']['missionId'] = $result['missionId'];
        $planData['launchAttributes']['runId'] = $result['runId'];
        $this->plans->save(ExecutionPlan::fromArray($planData));

        $conversation = $this->conversations->find($intake->conversationId());
        if ($conversation !== null) {
            $conversation->bindMission($result['missionId']);
            $conversation->append(new ConversationTurn(
                'system',
                'Mission launched: ' . $result['missionId'] . ' / run ' . $result['runId'],
                $at
            ));
            $this->conversations->save($conversation);
        }

        $this->engineeringMemory->recordPlanPatterns($plan, $intake->intent());

        return $result + [
            'planId' => $plan->id(),
            'status' => 'launched',
            'plan' => $plan->toPreviewArray(),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function retry(User $actor, string $missionId, array $attributes = []): array
    {
        $intake = $this->intakes->findByMissionId($missionId);
        $plan = $intake?->planId() !== null ? $this->plans->find($intake->planId()) : null;
        $attrs = $plan?->launchAttributes() ?? $attributes;
        $attrs['priorRunId'] = $intake?->runId();
        $projectId = $plan?->projectId();

        return $this->launch->retry($actor, $missionId, $projectId, $attrs);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function resume(string $runId, array $attributes = []): array
    {
        return $this->launch->resume($runId, $attributes);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function conversationForMission(string $missionId): ?array
    {
        $conversation = $this->conversations->findByMissionId($missionId);
        if ($conversation === null) {
            $intake = $this->intakes->findByMissionId($missionId);
            if ($intake !== null) {
                $conversation = $this->conversations->find($intake->conversationId());
            }
        }

        return $conversation?->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function planForMission(string $missionId): ?array
    {
        $intake = $this->intakes->findByMissionId($missionId);
        if ($intake?->planId() !== null) {
            $plan = $this->plans->find($intake->planId());
            if ($plan !== null) {
                return $plan->toPreviewArray();
            }
        }
        $plan = $this->plans->findByMissionId($missionId);

        return $plan?->toPreviewArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIntake(string $intakeId): ?array
    {
        $intake = $this->intakes->find($intakeId);

        return $intake === null ? null : $this->presentIntake($intake);
    }

    public function captureMemory(string $projectId, string $objective, bool $succeeded, array $constraints = []): void
    {
        $this->engineeringMemory->recordCompletion($projectId, $objective, $succeeded, $constraints);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function projectMemory(string $projectId): ?array
    {
        return $this->memory->find($projectId)?->toArray();
    }

    private function processUnderstanding(
        MissionIntake $intake,
        Conversation $conversation,
        ?string $projectIdHint,
        bool $reUnderstand = true,
    ): void {
        $at = Utc::now();
        $projects = $this->projects->list();
        $memory = null;
        if ($projectIdHint !== null) {
            $memory = $this->memory->getOrCreate($projectIdHint);
        } elseif ($intake->intent()?->projectId() !== null) {
            $memory = $this->memory->getOrCreate((string) $intake->intent()?->projectId());
        }

        if ($reUnderstand) {
            $intent = $this->understanding->understand(
                $intake->rawText(),
                $projectIdHint ?? $intake->projectIdHint(),
                $projects,
                $memory
            );
        } else {
            $intent = $intake->intent() ?? $this->understanding->understand(
                $intake->rawText(),
                $projectIdHint,
                $projects,
                $memory
            );
        }

        $intent = $this->clarification->enrich($intent, $projects, $memory);
        // Re-load memory if project resolved during enrich
        if ($intent->projectId() !== null) {
            $memory = $this->memory->getOrCreate($intent->projectId());
            $intent = $this->clarification->enrich($intent, $projects, $memory);
        }

        $intake->setIntent($intent, $at);

        $hard = $this->validator->validate($intent, $projects);
        if (!$hard['ok']) {
            // If failure is about missing project/target that questions can fix, clarify instead.
            $questions = $this->clarification->questions($intent, $projects, $memory);
            if ($questions !== []) {
                $intake->markClarifying($questions, $at);
                $conversation->append(new ConversationTurn(
                    'assistant',
                    'Need clarification: ' . implode(' | ', array_map(static fn ($q) => $q->prompt(), $questions)),
                    $at
                ));

                return;
            }
            $intake->markRejected((string) $hard['reason'], $at);
            $conversation->append(new ConversationTurn('assistant', 'Rejected: ' . $hard['reason'], $at));

            return;
        }

        $questions = $this->clarification->questions($intent, $projects, $memory);
        if ($questions !== []) {
            $intake->markClarifying($questions, $at);
            $conversation->append(new ConversationTurn(
                'assistant',
                'Need clarification: ' . implode(' | ', array_map(static fn ($q) => $q->prompt(), $questions)),
                $at
            ));

            return;
        }

        $intake->markReady($at);
        $conversation->append(new ConversationTurn(
            'assistant',
            'Mission intent ready. Review the execution plan, then confirm to start.',
            $at,
            $intent->toArray()
        ));
        // Eagerly build plan for preview
        $plan = $this->planner->plan($intake->id(), $intent);
        $this->plans->save($plan);
    }

    /** @return array<string, mixed> */
    private function presentIntake(MissionIntake $intake): array
    {
        $plan = $this->plans->findByIntakeId($intake->id());
        $conversation = $this->conversations->find($intake->conversationId());

        return [
            'intake' => $intake->toArray(),
            'questions' => array_map(static fn ($q) => $q->toArray(), $intake->questions()),
            'plan' => $plan?->toPreviewArray(),
            'conversation' => $conversation?->toArray(),
        ];
    }

    private function requireIntake(string $intakeId): MissionIntake
    {
        $intake = $this->intakes->find($intakeId);
        if ($intake === null) {
            throw new \InvalidArgumentException('Intake not found: ' . $intakeId);
        }

        return $intake;
    }
}
