<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Aep\Application\MissionEngine\MissionCheckpoint;
use Aep\Application\MissionEngine\MissionRunState;
use Aep\Application\MissionEngine\MissionTimeline;
use Aep\Application\MissionEngine\MissionTimelineEntry;
use Aep\Infrastructure\MissionEngine\JsonFileMissionRunRepository;
use Tests\Support\Assert;

/**
 * Infrastructure persistence tests for MissionEngine runs.
 */
final class MissionEngineRepositoryTest
{
    public function test_checkpoint_persistence_round_trip(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRunRepository($dir);
            $checkpoint = new MissionCheckpoint(
                'run_persist_1',
                'msn_persist_1',
                MissionRunState::WAITING,
                ['define_scope', 'start_inspection', 'inspection'],
                2,
                ['define_scope' => 1, 'start_inspection' => 1, 'inspection' => 1],
                16,
                '2026-07-30T12:00:00Z',
                'proj_1',
                ['gate.inspection' => 'approved'],
                'Waiting at gate'
            );
            $timeline = new MissionTimeline([
                new MissionTimelineEntry(
                    '2026-07-30T12:00:00Z',
                    'inspection',
                    'run_waiting',
                    MissionRunState::WAITING,
                    0.01,
                    'Waiting at gate'
                ),
            ]);

            $repo->save($checkpoint, $timeline);
            Assert::true($repo->exists('run_persist_1'));

            $loaded = $repo->getCheckpoint('run_persist_1');
            Assert::same('run_persist_1', $loaded->runId());
            Assert::same('msn_persist_1', $loaded->missionId());
            Assert::same(MissionRunState::WAITING, $loaded->engineState());
            Assert::same(2, $loaded->cursorIndex());
            Assert::same(16, $loaded->progressPercent());
            Assert::same('approved', $loaded->attributes()['gate.inspection'] ?? null);
            Assert::same(['define_scope', 'start_inspection'], $loaded->completedStepIds());

            Assert::true(is_file($dir . '/run_persist_1/checkpoint.json'));
            $raw = file_get_contents($dir . '/run_persist_1/checkpoint.json');
            Assert::true(is_string($raw));
            Assert::true(str_contains($raw, '"schemaVersion": 1'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_timeline_persistence_round_trip(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRunRepository($dir);
            $checkpoint = new MissionCheckpoint(
                'run_tl_1',
                'msn_tl_1',
                MissionRunState::RUNNING,
                ['a', 'b'],
                1,
                ['a' => 1],
                50,
                '2026-07-30T12:00:00Z'
            );
            $timeline = new MissionTimeline([
                new MissionTimelineEntry('2026-07-30T12:00:00Z', '', 'run_started', 'running', 0.0, 'start'),
                new MissionTimelineEntry('2026-07-30T12:00:01Z', 'a', 'step_finished', 'succeeded', 0.02, 'ok'),
                new MissionTimelineEntry('2026-07-30T12:00:02Z', 'b', 'step_started', 'running', 0.0, 'next'),
            ]);

            $repo->save($checkpoint, $timeline);
            $loaded = $repo->getTimeline('run_tl_1');
            Assert::same(3, count($loaded->entries()));
            Assert::same('run_started', $loaded->entries()[0]->event());
            Assert::same('a', $loaded->entries()[1]->step());
            Assert::same('succeeded', $loaded->entries()[1]->status());
            Assert::same(0.02, $loaded->entries()[1]->duration());
            Assert::same('step_started', $loaded->entries()[2]->event());

            Assert::true(is_file($dir . '/run_tl_1/timeline.json'));
        } finally {
            $this->removeDir($dir);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/aep_mission_run_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp dir.');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeDir($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
    }
}
