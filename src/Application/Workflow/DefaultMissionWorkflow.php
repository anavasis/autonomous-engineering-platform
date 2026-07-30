<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Canonical JSON for aep.default_mission@1.0.0 (matches DefaultMissionPlanFactory step ids).
 */
final class DefaultMissionWorkflow
{
    public static function json(): string
    {
        return <<<'JSON'
{
  "workflow": "aep.default_mission",
  "version": "1.0.0",
  "metadata": {
    "title": "Default mission workflow",
    "description": "ORCH-R11 declarative equivalent of DefaultMissionPlanFactory"
  },
  "parameters": [
    {
      "name": "allowedPaths",
      "type": "string[]",
      "required": false,
      "default": ["src/"]
    },
    {
      "name": "nonGoals",
      "type": "string[]",
      "required": false,
      "default": []
    },
    {
      "name": "executionAction",
      "type": "string",
      "required": false,
      "default": "implement"
    }
  ],
  "tasks": [
    {
      "id": "define_scope",
      "type": "mission.define_scope",
      "with": {
        "allowedPaths": "${params.allowedPaths}",
        "nonGoals": "${params.nonGoals}"
      }
    },
    {
      "id": "start_inspection",
      "type": "mission.start_inspection"
    },
    {
      "id": "submit_inspection",
      "type": "mission.submit_inspection",
      "with": {
        "summary": "Inspection package ready."
      }
    },
    {
      "id": "inspection",
      "type": "gate.manual",
      "with": {
        "gateId": "inspection"
      }
    },
    {
      "id": "approve_inspection",
      "type": "mission.approve_inspection"
    },
    {
      "id": "execute_implementation",
      "type": "execution.run",
      "with": {
        "action": "${params.executionAction}"
      }
    },
    {
      "id": "finish_implementation",
      "type": "mission.finish_implementation"
    },
    {
      "id": "run_validation",
      "type": "validation.run",
      "with": {
        "declaredPaths": "${params.allowedPaths}"
      }
    },
    {
      "id": "commit",
      "type": "gate.manual",
      "with": {
        "gateId": "commit"
      }
    },
    {
      "id": "approve_commit",
      "type": "mission.approve_commit"
    },
    {
      "id": "mark_pr_ready",
      "type": "mission.mark_pr_ready"
    },
    {
      "id": "complete_mission",
      "type": "mission.complete"
    }
  ]
}
JSON;
    }

    public static function register(InMemoryWorkflowLibrary $library): WorkflowDefinition
    {
        return $library->registerJson(self::json());
    }
}
