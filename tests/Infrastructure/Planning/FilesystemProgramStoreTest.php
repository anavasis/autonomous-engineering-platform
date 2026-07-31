<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Planning;

use Aep\Application\Planning\Model\PlanningEvent;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramSnapshot;
use Aep\Infrastructure\Planning\Store\FilesystemProgramStore;
use Tests\Support\Assert;

final class FilesystemProgramStoreTest
{
    public function test_save_events_snapshots_and_mission_index(): void
    {
        $root = sys_get_temp_dir() . '/aep_plan_store_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemProgramStore($root);
            $program = (new Program(
                'prg_test1',
                'T',
                'Objective for planning store test with tokens',
                Program::STATUS_PLANNED,
                '2026-01-01T00:00:00Z',
                '2026-01-01T00:00:00Z',
            ))->withSealedHashes();
            $store->save($program);
            $store->appendEvent(new PlanningEvent(
                PlanningEvent::makeId(),
                PlanningEvent::PROGRAM_CREATED,
                'prg_test1',
                '2026-01-01T00:00:00Z',
                ['ok' => true],
            ));
            $snap = (new ProgramSnapshot(
                ProgramSnapshot::makeId(),
                'prg_test1',
                1,
                '2026-01-01T00:00:01Z',
                $program->toArray(),
                [],
                [],
                'pev_x',
            ))->withIntegrity();
            $store->saveSnapshot($snap);
            $store->indexMission('msn_1', 'prg_test1');

            Assert::true($store->find('prg_test1') !== null);
            Assert::true(str_starts_with($store->find('prg_test1')?->integrityHash() ?? '', 'sha256:'));
            Assert::true(count($store->events('prg_test1')) >= 1);
            Assert::true(count($store->snapshots('prg_test1')) >= 1);
            Assert::true(str_starts_with($store->latestSnapshot('prg_test1')?->toArray()['integrityHash'] ?? '', 'sha256:'));
            Assert::same('prg_test1', $store->findProgramIdByMission('msn_1'));
            Assert::true(count($store->list(Program::STATUS_PLANNED)) >= 1);
        } finally {
            $this->removeDir($root);
        }
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
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
