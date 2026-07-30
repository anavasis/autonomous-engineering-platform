<?php

declare(strict_types=1);

namespace Tests\Domain;

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
use Tests\Support\Assert;

final class ProjectLifecycleTest
{
    private const AT = '2026-07-30T15:00:00Z';

    public function test_create_project(): void
    {
        $project = $this->newProject('prj_1');
        Assert::same(ProjectStatus::ACTIVE, $project->status()->toString());
        Assert::same('prj_1', $project->id()->toString());
        Assert::same('demo-app', $project->slug()->toString());
        Assert::same(0, $project->missionCount());
        Assert::same(0, $project->deploymentCount());
        Assert::same(null, $project->lastDeploymentAt());
        Assert::eventNames(['ProjectCreated'], $project->recordedEvents());
    }

    public function test_archive_project(): void
    {
        $project = $this->newProject('prj_arch');
        $project->pullRecordedEvents();
        $project->archive(self::AT);
        Assert::true($project->status()->isArchived());
        Assert::eventNames(['ProjectArchived'], $project->recordedEvents());
        Assert::throws(\DomainException::class, static function () use ($project): void {
            $project->addEnvironment(
                new Environment(new EnvironmentId('env1'), 'dev', new EnvironmentKind(EnvironmentKind::DEVELOPMENT)),
                self::AT
            );
        });
    }

    public function test_add_environment(): void
    {
        $project = $this->newProject('prj_env');
        $project->pullRecordedEvents();
        $project->addEnvironment(
            new Environment(new EnvironmentId('env_dev'), 'Development', new EnvironmentKind(EnvironmentKind::DEVELOPMENT)),
            self::AT
        );
        Assert::same(1, count($project->environments()));
        Assert::eventNames(['EnvironmentAdded'], $project->recordedEvents());
    }

    public function test_add_server(): void
    {
        $project = $this->newProject('prj_srv');
        $project->pullRecordedEvents();
        $project->addServerDefinition(
            new ServerDefinition(
                new ServerDefinitionId('srv_1'),
                'app-1',
                new HostRef('app.example.com'),
                new AccessMethod(AccessMethod::SSH),
                new SecretRef('vault', 'ssh/app-1')
            ),
            self::AT
        );
        Assert::same(1, count($project->servers()));
        Assert::eventNames(['ServerDefinitionAdded'], $project->recordedEvents());
    }

    public function test_bind_repository_once(): void
    {
        $project = $this->newProject('prj_repo');
        $project->pullRecordedEvents();
        $project->bindRepository(
            new RepositoryBinding('github', 'anavasis/demo', new SecretRef('vault', 'git/demo')),
            self::AT
        );
        Assert::true($project->repositoryBinding() !== null);
        Assert::eventNames(['RepositoryBound'], $project->recordedEvents());
        Assert::throws(\DomainException::class, static function () use ($project): void {
            $project->bindRepository(new RepositoryBinding('github', 'anavasis/other'), self::AT);
        });
    }

    public function test_invalid_host(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new HostRef('https://user:pass@host.example.com');
        });
    }

    public function test_invalid_secret_ref(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new SecretRef('vault', 'ghp_abcdefghijklmnopqrstuvwxyz0123456789');
        });
    }

    private function newProject(string $id): Project
    {
        return Project::create(
            new ProjectId($id),
            new ProjectSlug('demo-app'),
            'Demo App',
            new ActorRef('user', 'owner-1'),
            self::AT,
            'R7a project'
        );
    }
}
