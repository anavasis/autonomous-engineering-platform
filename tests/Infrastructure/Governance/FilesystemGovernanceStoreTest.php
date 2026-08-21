<?php
declare(strict_types=1);
namespace Tests\Infrastructure\Governance;

use Aep\Application\Governance\Model\AuditEntry;
use Aep\Application\Governance\Model\ChangeRequest;
use Aep\Application\Governance\Model\DeploymentPlan;
use Aep\Application\Governance\Model\Environment;
use Aep\Application\Governance\Model\GovernanceEvent;
use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Model\RollbackPlan;
use Aep\Infrastructure\Governance\Store\FilesystemGovernanceStore;
use Aep\Infrastructure\Governance\Store\JsonGovernanceSettingsStore;
use Tests\Support\Assert;

final class FilesystemGovernanceStoreTest
{
    public function test_persist_releases_deployments_audit_and_settings(): void
    {
        $root = sys_get_temp_dir() . '/aep_gov_store_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemGovernanceStore($root);
            $store->saveEnvironment(Environment::fromArray([
                'environmentId' => 'env_development',
                'name' => 'Development',
                'kind' => 'development',
                'promotionOrder' => 10,
            ]));
            $store->saveChangeRequest(ChangeRequest::fromArray([
                'changeRequestId' => 'cr_1',
                'title' => 'CR',
                'status' => 'open',
                'createdAtUtc' => '2026-01-01T00:00:00Z',
                'updatedAtUtc' => '2026-01-01T00:00:00Z',
                'requestedBy' => 'tester',
            ]));
            $release = ReleaseRecord::fromArray([
                'releaseId' => 'rel_1',
                'version' => '1.0.0',
                'status' => 'draft',
                'createdAtUtc' => '2026-01-01T00:00:00Z',
                'updatedAtUtc' => '2026-01-01T00:00:00Z',
                'title' => 'R',
            ]);
            $store->saveRelease($release);
            $store->saveDeployment(new DeploymentPlan(
                'dep_1', 'rel_1', 'env_development', DeploymentPlan::STATUS_FINISHED,
                '2026-01-01T00:00:00Z', '2026-01-01T00:00:01Z'
            ));
            $store->saveRollback(new RollbackPlan(
                'rb_1', 'rel_1', 'rel_0', 'env_development', RollbackPlan::STATUS_PLANNED,
                '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'
            ));
            $store->appendEvent(new GovernanceEvent('gev_1', GovernanceEvent::RELEASE_CREATED, '2026-01-01T00:00:00Z', [], 'rel_1'));
            $store->appendAudit(new AuditEntry('aud_1', '2026-01-01T00:00:00Z', 'tester', 'release.create', 'release', 'rel_1'));
            $store->saveCompliance(['pass' => true, 'violations' => []]);
            $store->saveMetrics(['releasesCreated' => 1]);

            Assert::same('rel_1', $store->findRelease('rel_1')?->releaseId());
            Assert::same('cr_1', $store->findChangeRequest('cr_1')?->changeRequestId());
            Assert::same('dep_1', $store->findDeployment('dep_1')?->deploymentId());
            Assert::same('rb_1', $store->findRollback('rb_1')?->rollbackId());
            Assert::true(count($store->events()) >= 1);
            Assert::true(count($store->audit()) >= 1);
            Assert::same(true, $store->compliance()['pass']);
            $settings = new JsonGovernanceSettingsStore($root);
            Assert::same(true, $settings->get()['engineeringGovernance']);
        } finally {
            $this->removeDir($root);
        }
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
