<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Aep\Domain\Mission\ValueObject\MissionId;
use Aep\Domain\Mission\ValueObject\MissionState;
use Aep\Domain\Mission\ValueObject\ValidationResult;
use Aep\Infrastructure\Persistence\JsonFileMissionRepository;
use Tests\Support\Assert;
use Tests\Support\MissionFixtures;

/**
 * ORCH-R4 JSON durable persistence tests.
 */
final class JsonFileMissionRepositoryTest
{
    public function test_round_trip_save_and_load(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRepository($dir);
            $mission = MissionFixtures::newDraft('msn_json_1');
            $mission->defineScope(MissionFixtures::scope());
            $mission->startInspection(MissionFixtures::AT);
            $mission->submitInspection(MissionFixtures::findings(), MissionFixtures::AT);
            $mission->approveInspection(MissionFixtures::inspectionApproval(true), MissionFixtures::AT);
            $mission->finishImplementation(MissionFixtures::AT);
            $mission->recordValidation(ValidationResult::passed('ok'), MissionFixtures::AT);
            $mission->pullRecordedEvents();

            $repo->save($mission);
            $loaded = $repo->get(new MissionId('msn_json_1'));

            Assert::same('msn_json_1', $loaded->id()->toString());
            Assert::same(MissionState::AWAITING_COMMIT_APPROVAL, $loaded->state()->toString());
            Assert::same($mission->target()->toString(), $loaded->target()->toString());
            Assert::same($mission->brief()->objective(), $loaded->brief()->objective());
            Assert::same($mission->createdBy()->toString(), $loaded->createdBy()->toString());
            Assert::same($mission->createdAtUtc(), $loaded->createdAtUtc());
            Assert::true($loaded->scopePolicy() !== null);
            Assert::true($loaded->inspectionFindings() !== null);
            Assert::true($loaded->inspectionApproval() !== null);
            Assert::true($loaded->lastValidationResult() !== null);
            Assert::true($loaded->lastValidationResult()->isPassed());
            Assert::same([], $loaded->recordedEvents());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_missing_mission_throws(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRepository($dir);
            Assert::throws(\RuntimeException::class, static function () use ($repo): void {
                $repo->get(new MissionId('msn_missing'));
            });
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_exists(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRepository($dir);
            $id = new MissionId('msn_exists');
            Assert::true(!$repo->exists($id));
            $repo->save(MissionFixtures::newDraft('msn_exists'));
            Assert::true($repo->exists($id));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_overwrite_semantics(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRepository($dir);
            $mission = MissionFixtures::newDraft('msn_over');
            $repo->save($mission);

            $mission->defineScope(MissionFixtures::scope());
            $mission->startInspection(MissionFixtures::AT);
            $repo->save($mission);

            $loaded = $repo->get(new MissionId('msn_over'));
            Assert::same(MissionState::INSPECTING, $loaded->state()->toString());
            Assert::true($loaded->scopePolicy() !== null);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_loaded_mission_has_empty_event_buffer(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRepository($dir);
            $mission = MissionFixtures::newDraft('msn_evbuf');
            Assert::true($mission->recordedEvents() !== []);
            $repo->save($mission);

            $loaded = $repo->get(new MissionId('msn_evbuf'));
            Assert::same([], $loaded->recordedEvents());
            Assert::same([], $loaded->pullRecordedEvents());
            Assert::same(MissionState::DRAFT, $loaded->state()->toString());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_schema_version_written(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileMissionRepository($dir);
            $repo->save(MissionFixtures::newDraft('msn_schema'));
            $raw = file_get_contents($dir . DIRECTORY_SEPARATOR . 'msn_schema.json');
            Assert::true($raw !== false);
            /** @var array<string, mixed> $data */
            $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            Assert::same(JsonFileMissionRepository::SCHEMA_VERSION, $data['schemaVersion']);
            Assert::true(!array_key_exists('recordedEvents', $data));
        } finally {
            $this->removeDir($dir);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aep_orch_r4_' . bin2hex(random_bytes(8));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp dir: ' . $dir);
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
