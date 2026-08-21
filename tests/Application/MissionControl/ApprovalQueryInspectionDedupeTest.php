<?php

declare(strict_types=1);

namespace Tests\Application\MissionControl;

use Aep\Application\Mission\Command\ApprovalCommand;
use Aep\Application\Mission\Command\CreateMission;
use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\Mission\Command\SubmitInspection;
use Aep\Application\Mission\Command\TimedMissionCommand;
use Aep\Application\Mission\MissionCommandService;
use Aep\Application\MissionControl\Query\ApprovalQueryService;
use Aep\Application\MissionControl\Query\MissionQueryService;
use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionEngine\MissionTimeline;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Infrastructure\MissionControl\Catalog\JsonMissionCatalog;
use Aep\Infrastructure\MissionControl\Catalog\JsonRunCatalog;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
use Aep\Infrastructure\Persistence\JsonFileMissionRepository;
use Tests\Support\Assert;

final class ApprovalQueryInspectionDedupeTest
{
    public function test_waiting_inspection_gate_produces_exactly_one_actionable_row(): void
    {
        $h = $this->harness();
        try {
            $this->missionAwaitingInspection($h, 'msn_gate_1');
            $this->saveWaitingRun($h, 'run_gate_1', 'msn_gate_1', 'inspection', 'Manual gate pending: inspection');

            $items = $h['approvals']->list();
            $forMission = array_values(array_filter(
                $items,
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_gate_1'
            ));
            Assert::same(1, count($forMission));
            Assert::same('implementation_gate', $forMission[0]['kind']);
            Assert::same('inspection', $forMission[0]['gateId']);
            Assert::same('run_gate_1', $forMission[0]['runId']);
        } finally {
            $this->removeDir($h['root']);
        }
    }

    public function test_no_duplicate_inspection_plus_gate_pair(): void
    {
        $h = $this->harness();
        try {
            $this->missionAwaitingInspection($h, 'msn_dup_1');
            $this->saveWaitingRun($h, 'run_dup_1', 'msn_dup_1', 'inspection', 'Manual gate pending: inspection');

            $kinds = [];
            foreach ($h['approvals']->list() as $item) {
                if (($item['missionId'] ?? null) !== 'msn_dup_1') {
                    continue;
                }
                $kinds[] = (string) ($item['kind'] ?? '');
            }
            Assert::same(['implementation_gate'], $kinds);
            Assert::true(!in_array('inspection', $kinds, true));
        } finally {
            $this->removeDir($h['root']);
        }
    }

    public function test_unrelated_inspection_approval_without_gate_wait_remains(): void
    {
        $h = $this->harness();
        try {
            $this->missionAwaitingInspection($h, 'msn_plain_1');
            // No waiting run — standalone inspection row must remain.

            $items = array_values(array_filter(
                $h['approvals']->list(),
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_plain_1'
            ));
            Assert::same(1, count($items));
            Assert::same('inspection', $items[0]['kind']);
            Assert::same(null, $items[0]['runId'] ?? null);
        } finally {
            $this->removeDir($h['root']);
        }
    }

    public function test_waiting_commit_gate_produces_exactly_one_actionable_row(): void
    {
        $h = $this->harness();
        try {
            $this->missionAwaitingCommit($h, 'msn_commit_1');
            $this->saveWaitingRun($h, 'run_commit_1', 'msn_commit_1', 'commit', 'Manual gate pending: commit');

            $items = array_values(array_filter(
                $h['approvals']->list(),
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_commit_1'
            ));
            Assert::same(1, count($items));
            Assert::same('implementation_gate', $items[0]['kind']);
            Assert::same('commit', $items[0]['gateId'] ?? null);
            Assert::true(!in_array('merge', array_map(static fn (array $i): string => (string) ($i['kind'] ?? ''), $items), true));
        } finally {
            $this->removeDir($h['root']);
        }
    }

    public function test_unrelated_commit_approval_without_gate_wait_remains(): void
    {
        $h = $this->harness();
        try {
            $this->missionAwaitingCommit($h, 'msn_commit_plain');
            $items = array_values(array_filter(
                $h['approvals']->list(),
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_commit_plain'
            ));
            Assert::same(1, count($items));
            Assert::same('merge', $items[0]['kind']);
        } finally {
            $this->removeDir($h['root']);
        }
    }

    public function test_commit_approval_behavior_unchanged(): void
    {
        // Retained name for continuity; commit gate now mirrors inspection dedupe.
        $this->test_waiting_commit_gate_produces_exactly_one_actionable_row();
    }

    public function test_waiting_on_unrelated_gate_keeps_standalone_inspection_row(): void
    {
        $h = $this->harness();
        try {
            $this->missionAwaitingInspection($h, 'msn_other_gate');
            $plan = [
                'define_scope',
                'start_inspection',
                'submit_inspection',
                'other',
                'approve_inspection',
            ];
            $checkpoint = new MissionCheckpoint(
                'run_other',
                'msn_other_gate',
                MissionRunState::WAITING,
                $plan,
                3,
                [],
                25,
                '2026-08-04T10:05:00Z',
                null,
                ['gate' => 'other'],
                'Manual gate pending: other'
            );
            $h['runRepo']->save($checkpoint, new MissionTimeline());

            $items = array_values(array_filter(
                $h['approvals']->list(),
                static fn (array $i): bool => ($i['missionId'] ?? null) === 'msn_other_gate'
            ));
            $kinds = array_map(static fn (array $i): string => (string) ($i['kind'] ?? ''), $items);
            sort($kinds);
            Assert::same(['implementation_gate', 'inspection'], $kinds);
        } finally {
            $this->removeDir($h['root']);
        }
    }

    /**
     * @return array{
     *   root: string,
     *   missions: MissionCommandService,
     *   missionRepo: JsonFileMissionRepository,
     *   runRepo: JsonFileMissionRunRepository,
     *   approvals: ApprovalQueryService
     * }
     */
    private function harness(): array
    {
        $root = sys_get_temp_dir() . '/aep_appr_dedupe_' . bin2hex(random_bytes(4));
        $missionsDir = $root . '/missions';
        $runsDir = $root . '/runs';
        mkdir($missionsDir, 0775, true);
        mkdir($runsDir, 0775, true);
        $missionRepo = new JsonFileMissionRepository($missionsDir);
        $runRepo = new JsonFileMissionRunRepository($runsDir);
        $missionCatalog = new JsonMissionCatalog($missionRepo, $missionsDir);
        $runCatalog = new JsonRunCatalog($runRepo, $runsDir);
        $missionQuery = new MissionQueryService($missionCatalog, $runCatalog);

        return [
            'root' => $root,
            'missions' => new MissionCommandService($missionRepo),
            'missionRepo' => $missionRepo,
            'runRepo' => $runRepo,
            'approvals' => new ApprovalQueryService($missionCatalog, $missionQuery),
        ];
    }

    /** @param array<string, mixed> $h */
    private function missionAwaitingInspection(array $h, string $missionId): void
    {
        /** @var MissionCommandService $missions */
        $missions = $h['missions'];
        $at = '2026-08-04T10:00:00Z';
        $missions->create(new CreateMission($missionId, 'github', 'owner/repo', 'obj', 'user', 'tester', $at));
        $missions->defineScope(new DefineScope($missionId, ['src/'], []));
        $missions->startInspection(new TimedMissionCommand($missionId, $at));
        $missions->submitInspection(new SubmitInspection($missionId, 'ready', $at));
        Assert::same(MissionState::AWAITING_INSPECTION_APPROVAL, $h['missionRepo']->get(new \Aep\Domain\Mission\ValueObject\MissionId($missionId))->state()->toString());
    }

    /** @param array<string, mixed> $h */
    private function missionAwaitingCommit(array $h, string $missionId): void
    {
        /** @var MissionCommandService $missions */
        $missions = $h['missions'];
        $at = '2026-08-04T10:00:00Z';
        $missions->create(new CreateMission($missionId, 'github', 'owner/repo', 'obj', 'user', 'tester', $at));
        $missions->defineScope(new DefineScope($missionId, ['src/'], []));
        $missions->startInspection(new TimedMissionCommand($missionId, $at));
        $missions->submitInspection(new SubmitInspection($missionId, 'ready', $at));
        $missions->approveInspection(new ApprovalCommand($missionId, 'user', 'tester', $at));
        $missions->finishImplementation(new TimedMissionCommand($missionId, $at));
        $missions->recordValidation(new \Aep\Application\Mission\Command\RecordValidation($missionId, 'passed', 'ok', $at));
        // finishCorrection not needed if validation passed → validating → awaiting_commit
        // After passed validation mission goes to awaiting_commit_approval
        Assert::same(
            MissionState::AWAITING_COMMIT_APPROVAL,
            $h['missionRepo']->get(new \Aep\Domain\Mission\ValueObject\MissionId($missionId))->state()->toString()
        );
    }

    /** @param array<string, mixed> $h */
    private function saveWaitingRun(
        array $h,
        string $runId,
        string $missionId,
        string $gateStepId,
        string $message,
    ): void {
        $plan = [
            'define_scope',
            'start_inspection',
            'submit_inspection',
            'inspection',
            'approve_inspection',
            'execute_implementation',
            'finish_implementation',
            'run_validation',
            'commit',
            'approve_commit',
            'mark_pr_ready',
            'complete_mission',
        ];
        $cursor = array_search($gateStepId, $plan, true);
        Assert::true($cursor !== false);
        $checkpoint = new MissionCheckpoint(
            $runId,
            $missionId,
            MissionRunState::WAITING,
            $plan,
            (int) $cursor,
            [],
            25,
            '2026-08-04T10:05:00Z',
            null,
            ['gate' => $gateStepId],
            $message
        );
        $h['runRepo']->save($checkpoint, new MissionTimeline());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
