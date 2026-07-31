<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Workflow;

use Aep\Application\Workflow\DefaultMissionWorkflow;
use Aep\Application\Workflow\WorkflowParser;
use Aep\Infrastructure\Workflow\JsonFileWorkflowLibrary;
use Tests\Support\Assert;

final class JsonFileWorkflowLibraryTest
{
    public function test_round_trip_load(): void
    {
        $dir = sys_get_temp_dir() . '/aep_wf_' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        try {
            $library = new JsonFileWorkflowLibrary($dir);
            $json = DefaultMissionWorkflow::json();
            $definition = (new WorkflowParser())->parse($json);
            $library->save($definition, $json);

            Assert::true($library->exists('aep.default_mission', '1.0.0'));
            $loaded = $library->get('aep.default_mission', '1.0.0');
            Assert::same('aep.default_mission', $loaded->workflow());
            Assert::same('1.0.0', $loaded->version());
            Assert::same(12, count($loaded->tasks()));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
