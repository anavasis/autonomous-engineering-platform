<?php

declare(strict_types=1);

namespace Tests\Application;

use Aep\Application\Project\Command\AddEnvironment;
use Aep\Application\Project\Command\ArchiveProject;
use Aep\Application\Project\Command\CreateProject;
use Aep\Application\Project\ProjectCommandService;
use Aep\Domain\Project\ValueObject\EnvironmentKind;
use Aep\Domain\Project\ValueObject\ProjectStatus;
use Aep\Infrastructure\Persistence\InMemoryProjectRepository;
use Tests\Support\Assert;

final class ProjectCommandServiceTest
{
    private const AT = '2026-07-30T15:00:00Z';

    public function test_create_project(): void
    {
        $service = new ProjectCommandService(new InMemoryProjectRepository());
        $result = $service->create(new CreateProject(
            'prj_app_1',
            'demo-app',
            'Demo App',
            'user',
            'owner-1',
            self::AT,
            'desc'
        ));
        Assert::same('prj_app_1', $result->projectId()->toString());
        Assert::same(ProjectStatus::ACTIVE, $result->status()->toString());
        Assert::eventNames(['ProjectCreated'], $result->events());
    }

    public function test_invalid_command_flow(): void
    {
        Assert::throws(\InvalidArgumentException::class, static function (): void {
            new CreateProject('', 'demo-app', 'Demo', 'user', 'u1', self::AT);
        });

        $service = new ProjectCommandService(new InMemoryProjectRepository());
        $service->create(new CreateProject('prj_app_2', 'demo-app', 'Demo', 'user', 'u1', self::AT));
        Assert::throws(\RuntimeException::class, static function () use ($service): void {
            $service->create(new CreateProject('prj_app_2', 'demo-app', 'Demo', 'user', 'u1', self::AT));
        });

        Assert::throws(\RuntimeException::class, static function () use ($service): void {
            $service->addEnvironment(new AddEnvironment(
                'prj_missing',
                'env1',
                'dev',
                EnvironmentKind::DEVELOPMENT,
                self::AT
            ));
        });

        $service->archive(new ArchiveProject('prj_app_2', self::AT));
        Assert::throws(\DomainException::class, static function () use ($service): void {
            $service->addEnvironment(new AddEnvironment(
                'prj_app_2',
                'env1',
                'dev',
                EnvironmentKind::DEVELOPMENT,
                self::AT
            ));
        });
    }
}
