<?php
declare(strict_types=1);
namespace Tests\Infrastructure\Governance;

use Aep\Application\Governance\Policy\ApprovalPolicy;
use Aep\Application\Governance\Policy\CompliancePolicy;
use Aep\Application\Governance\Policy\SecurityPolicy;
use Aep\Application\Governance\Service\AuditManager;
use Aep\Application\Governance\Service\GovernanceManager;
use Aep\Application\Governance\Service\GovernanceObserveFacade;
use Aep\Application\Governance\Service\ReleaseManager;
use Aep\Application\Governance\Service\ReleasePipeline;
use Aep\Application\Planning\Model\MissionGraph;
use Aep\Application\Planning\Model\Program;
use Aep\Application\Planning\Model\ProgramNode;
use Aep\Application\Planning\Port\PlanningLaunchPort;
use Aep\Infrastructure\Governance\Adapter\GovernanceLaunchAdapter;
use Aep\Infrastructure\Governance\Adapter\GovernanceObserveAdapter;
use Aep\Infrastructure\Governance\Store\FilesystemGovernanceStore;
use Aep\Infrastructure\Governance\Store\JsonGovernanceSettingsStore;
use Tests\Support\Assert;

final class GovernanceAdaptersTest
{
    public function test_launch_adapter_passthrough_preserves_shape(): void
    {
        $root = sys_get_temp_dir() . '/aep_gov_adapt_' . bin2hex(random_bytes(4));
        try {
            $wire = $this->wire($root);
            $inner = new class implements PlanningLaunchPort {
                public function launchNode(Program $program, ProgramNode $node, string $actorId): array
                {
                    return ['missionId' => 'msn_gov_1', 'runId' => 'run_gov_1', 'message' => 'ok'];
                }
            };
            $adapter = new GovernanceLaunchAdapter($inner, $wire['facade'], $wire['settings']);
            $node = new ProgramNode('n1', 'Implement', 'implement module');
            $program = new Program('prg_1', 'P', 'implement module', Program::STATUS_RUNNING, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', new MissionGraph([$node], []));
            $result = $adapter->launchNode($program, $node, 'tester');
            Assert::same('msn_gov_1', $result['missionId']);
            Assert::same('run_gov_1', $result['runId']);
            Assert::same(0, count($wire['store']->listChangeRequests()));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_observe_adapter_creates_release_on_patch_seal(): void
    {
        $root = sys_get_temp_dir() . '/aep_gov_adapt_' . bin2hex(random_bytes(4));
        try {
            $wire = $this->wire($root);
            $adapter = new GovernanceObserveAdapter($wire['facade']);
            $adapter->onPatchSealed([
                'patchId' => 'pat_gov_1',
                'missionId' => 'msn_1',
                'version' => '1.0.0-rc.seal',
            ], 'tester');
            Assert::true(count($wire['store']->listReleases()) >= 1);
            Assert::true(count($wire['store']->listChangeRequests()) >= 1);
            $adapter->onPatchApproved(['patchId' => 'pat_gov_2'], 'tester');
            Assert::same(1, count($wire['store']->listReleases()));
        } finally {
            $this->removeDir($root);
        }
    }

    /** @return array{store: FilesystemGovernanceStore, settings: JsonGovernanceSettingsStore, facade: GovernanceObserveFacade} */
    private function wire(string $root): array
    {
        $settings = new JsonGovernanceSettingsStore($root);
        $store = new FilesystemGovernanceStore($root);
        $audit = new AuditManager($store);
        $manager = new GovernanceManager($store, $settings, $audit);
        $pipeline = new ReleasePipeline($store, $settings, new CompliancePolicy(), new SecurityPolicy());
        $releases = new ReleaseManager($store, $settings, $pipeline, $audit, new ApprovalPolicy());
        $facade = new GovernanceObserveFacade($releases, $manager, $settings);
        return compact('store', 'settings', 'facade');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
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
