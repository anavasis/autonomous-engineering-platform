<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Aep\Domain\Mission\ValueObject\ActorRef;
use Aep\Domain\Project\Entity\Environment;
use Aep\Domain\Project\Entity\ServerDefinition;
use Aep\Domain\Project\Project;
use Aep\Domain\Project\ValueObject\AccessMethod;
use Aep\Domain\Project\ValueObject\EnvironmentId;
use Aep\Domain\Project\ValueObject\EnvironmentKind;
use Aep\Domain\Project\ValueObject\HostRef;
use Aep\Domain\Project\ValueObject\ProjectId;
use Aep\Domain\Project\ValueObject\ProjectSlug;
use Aep\Domain\Project\ValueObject\ProjectStatus;
use Aep\Domain\Project\ValueObject\RepositoryBinding;
use Aep\Domain\Project\ValueObject\SecretRef;
use Aep\Domain\Project\ValueObject\ServerDefinitionId;
use Aep\Infrastructure\Persistence\JsonFileProjectRepository;
use Tests\Support\Assert;

final class JsonFileProjectRepositoryTest
{
    private const AT = '2026-07-30T15:00:00Z';

    public function test_json_roundtrip(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileProjectRepository($dir);
            $project = Project::create(
                new ProjectId('prj_json_1'),
                new ProjectSlug('json-demo'),
                'JSON Demo',
                new ActorRef('user', 'owner-1'),
                self::AT,
                'roundtrip'
            );
            $project->addEnvironment(
                new Environment(new EnvironmentId('env_dev'), 'dev', new EnvironmentKind(EnvironmentKind::DEVELOPMENT)),
                self::AT
            );
            $project->addServerDefinition(
                new ServerDefinition(
                    new ServerDefinitionId('srv_1'),
                    'web',
                    new HostRef('web.example.com'),
                    new AccessMethod(AccessMethod::SSH),
                    new SecretRef('vault', 'ssh/web')
                ),
                self::AT
            );
            $project->bindRepository(new RepositoryBinding('github', 'anavasis/json-demo'), self::AT);
            $project->pullRecordedEvents();
            $repo->save($project);

            $loaded = $repo->get(new ProjectId('prj_json_1'));
            Assert::same('prj_json_1', $loaded->id()->toString());
            Assert::same('json-demo', $loaded->slug()->toString());
            Assert::same(ProjectStatus::ACTIVE, $loaded->status()->toString());
            Assert::same(1, count($loaded->environments()));
            Assert::same(1, count($loaded->servers()));
            Assert::true($loaded->repositoryBinding() !== null);
            Assert::same([], $loaded->recordedEvents());
            Assert::same(0, $loaded->missionCount());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_overwrite(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileProjectRepository($dir);
            $project = Project::create(
                new ProjectId('prj_over'),
                new ProjectSlug('over-demo'),
                'Over',
                new ActorRef('user', 'owner-1'),
                self::AT
            );
            $repo->save($project);
            $project->archive(self::AT);
            $repo->save($project);
            $loaded = $repo->get(new ProjectId('prj_over'));
            Assert::true($loaded->status()->isArchived());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_exists(): void
    {
        $dir = $this->tempDir();
        try {
            $repo = new JsonFileProjectRepository($dir);
            $id = new ProjectId('prj_exists');
            Assert::true(!$repo->exists($id));
            $repo->save(Project::create(
                $id,
                new ProjectSlug('exists-demo'),
                'Exists',
                new ActorRef('user', 'owner-1'),
                self::AT
            ));
            Assert::true($repo->exists($id));
        } finally {
            $this->removeDir($dir);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aep_orch_r7a_' . bin2hex(random_bytes(8));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp dir.');
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
            @unlink($dir . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($dir);
    }
}
