<?php
declare(strict_types=1);
namespace Tests\Application\Governance;

use Aep\Application\Governance\Policy\ApprovalPolicy;
use Aep\Application\Governance\Policy\CompliancePolicy;
use Aep\Application\Governance\Policy\SecurityPolicy;
use Aep\Application\Governance\Service\AuditManager;
use Aep\Application\Governance\Service\GovernanceManager;
use Aep\Application\Governance\Service\ReleaseManager;
use Aep\Application\Governance\Service\ReleasePipeline;
use Aep\Infrastructure\Governance\Store\FilesystemGovernanceStore;
use Aep\Infrastructure\Governance\Store\JsonGovernanceSettingsStore;
use Tests\Support\Assert;

final class ReleaseManagerTest
{
    public function test_release_pipeline_approve_and_deploy(): void
    {
        $root = sys_get_temp_dir() . '/aep_gov_app_' . bin2hex(random_bytes(4));
        try {
            $mgr = $this->managers($root);
            $release = $mgr['releases']->create([
                'title' => 'RC',
                'version' => '1.0.0-rc.test',
                'patchIds' => ['pat_1'],
            ], 'tester');
            Assert::true(str_starts_with($release->releaseId(), 'rel_'));
            $candidate = $mgr['releases']->submitCandidate($release->releaseId(), [
                'reviewed' => true,
                'tests' => 'passed',
                'securityScan' => 'passed',
            ], 'tester');
            Assert::same('candidate', $candidate->record()->status());
            $mgr['releases']->approve($release->releaseId(), 'engineering', 'eng');
            $approved = $mgr['releases']->approve($release->releaseId(), 'security', 'sec');
            Assert::same('approved', $approved->status());
            $plan = $mgr['releases']->deploy($release->releaseId(), 'env_development', 'tester');
            Assert::same('finished', $plan->status());
            Assert::true(count($mgr['store']->audit(50)) >= 1);
            Assert::true(($mgr['store']->metrics()['deploymentsFinished'] ?? 0) >= 1);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_hard_compliance_blocks_production_without_patches(): void
    {
        $root = sys_get_temp_dir() . '/aep_gov_app_' . bin2hex(random_bytes(4));
        try {
            $mgr = $this->managers($root);
            $mgr['settings']->put(['hardComplianceGate' => true, 'requirePatchEvidence' => true]);
            $release = $mgr['releases']->create(['title' => 'No patches', 'version' => '1.0.0'], 'tester');
            $mgr['releases']->submitCandidate($release->releaseId(), [
                'reviewed' => true,
                'tests' => 'passed',
                'securityScan' => 'passed',
            ], 'tester');
            $mgr['releases']->approve($release->releaseId(), 'engineering', 'eng');
            $mgr['releases']->approve($release->releaseId(), 'security', 'sec');
            $blocked = false;
            try {
                $mgr['releases']->promote($release->releaseId(), 'env_production', 'tester');
            } catch (\Throwable $e) {
                $blocked = str_contains($e->getMessage(), 'Compliance') || str_contains($e->getMessage(), 'patch');
            }
            Assert::true($blocked);
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_change_request_and_observe_settings_flag(): void
    {
        $root = sys_get_temp_dir() . '/aep_gov_app_' . bin2hex(random_bytes(4));
        try {
            $mgr = $this->managers($root);
            Assert::same(true, $mgr['settings']->get()['engineeringGovernance']);
            $cr = $mgr['manager']->createChangeRequest(['title' => 'CR1', 'patchIds' => ['p1']], 'tester');
            Assert::true(str_starts_with($cr->changeRequestId(), 'cr_'));
            Assert::same(1, count($mgr['store']->listChangeRequests('open')));
        } finally {
            $this->removeDir($root);
        }
    }

    /** @return array{store: FilesystemGovernanceStore, settings: JsonGovernanceSettingsStore, releases: ReleaseManager, manager: GovernanceManager} */
    private function managers(string $root): array
    {
        $settings = new JsonGovernanceSettingsStore($root);
        $store = new FilesystemGovernanceStore($root);
        $audit = new AuditManager($store);
        $manager = new GovernanceManager($store, $settings, $audit);
        $manager->seedFromConfig(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/deploy/governance.json'), true));
        $pipeline = new ReleasePipeline($store, $settings, new CompliancePolicy(), new SecurityPolicy());
        $releases = new ReleaseManager($store, $settings, $pipeline, $audit, new ApprovalPolicy());
        return compact('store', 'settings', 'releases', 'manager');
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
