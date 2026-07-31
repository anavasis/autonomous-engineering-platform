<?php

declare(strict_types=1);

namespace Aep\Application\MissionEngine\Step;

use Aep\Application\Mission\Command\DefineScope;
use Aep\Application\MissionEngine\MissionContext;
use Aep\Application\MissionEngine\MissionStep;
use Aep\Application\MissionEngine\StepResult;

final class DefineScopeStep implements MissionStep
{
    public function id(): string
    {
        return 'define_scope';
    }

    public function name(): string
    {
        return 'Define scope';
    }

    public function execute(MissionContext $context): StepResult
    {
        try {
            /** @var list<string> $paths */
            $paths = $context->attribute('allowedPaths', ['src/']);
            if (!is_array($paths) || $paths === []) {
                $paths = ['src/'];
            }
            $paths = array_values(array_filter($paths, static fn ($p) => is_string($p) && $p !== ''));
            if ($paths === []) {
                $paths = ['src/'];
            }

            /** @var list<string> $nonGoals */
            $nonGoals = $context->attribute('nonGoals', []);
            if (!is_array($nonGoals)) {
                $nonGoals = [];
            }
            $nonGoals = array_values(array_filter($nonGoals, static fn ($p) => is_string($p)));

            $context->missions()->defineScope(new DefineScope(
                $context->missionId(),
                $paths,
                $nonGoals
            ));

            return StepResult::succeeded('Scope defined.');
        } catch (\InvalidArgumentException $e) {
            return StepResult::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return StepResult::failed($e->getMessage());
        }
    }
}
