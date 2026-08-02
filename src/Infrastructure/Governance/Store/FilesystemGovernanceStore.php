<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Governance\Store;

use Aep\Application\Governance\Model\AuditEntry;
use Aep\Application\Governance\Model\ChangeRequest;
use Aep\Application\Governance\Model\DeploymentPlan;
use Aep\Application\Governance\Model\DeploymentTarget;
use Aep\Application\Governance\Model\Environment;
use Aep\Application\Governance\Model\GovernanceEvent;
use Aep\Application\Governance\Model\ReleaseRecord;
use Aep\Application\Governance\Model\RollbackPlan;
use Aep\Application\Governance\Port\GovernanceStore;

final class FilesystemGovernanceStore implements GovernanceStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/change-requests',
            $this->root . '/releases',
            $this->root . '/environments',
            $this->root . '/targets',
            $this->root . '/deployments',
            $this->root . '/rollbacks',
            $this->root . '/audit',
            $this->root . '/events',
            $this->root . '/compliance',
            $this->root . '/metrics',
            $this->root . '/index/by-patch',
            $this->root . '/index/by-status',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create governance store: ' . $dir);
            }
        }
        if (!is_file($this->root . '/metrics/snapshot.json')) {
            $this->writeJson($this->root . '/metrics/snapshot.json', [
                'releasesCreated' => 0, 'deploymentsFinished' => 0, 'rollbacksCompleted' => 0,
            ]);
        }
        if (!is_file($this->root . '/compliance/latest.json')) {
            $this->writeJson($this->root . '/compliance/latest.json', ['pass' => true, 'violations' => []]);
        }
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create: ' . $dir);
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) { return null; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?: 'unknown';
    }

    public function saveChangeRequest(ChangeRequest $cr): void
    {
        $this->writeJson($this->root . '/change-requests/' . $this->safe($cr->changeRequestId()) . '.json', $cr->toArray());
    }

    public function findChangeRequest(string $id): ?ChangeRequest
    {
        $data = $this->readJson($this->root . '/change-requests/' . $this->safe($id) . '.json');
        return $data !== null ? ChangeRequest::fromArray($data) : null;
    }

    public function listChangeRequests(?string $status = null): array
    {
        $out = [];
        foreach (glob($this->root . '/change-requests/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null) { continue; }
            $cr = ChangeRequest::fromArray($data);
            if ($status !== null && $status !== '' && $cr->status() !== $status) { continue; }
            $out[] = $cr;
        }
        return $out;
    }

    public function saveRelease(ReleaseRecord $release): void
    {
        $dir = $this->root . '/releases/' . $this->safe($release->releaseId());
        $this->writeJson($dir . '/release.json', $release->toArray());
        $this->writeJson($dir . '/gates.json', ['items' => $release->gates()]);
        $this->writeJson($dir . '/approvals.json', ['items' => $release->approvals()]);
        $this->writeJson($this->root . '/index/by-status/' . $this->safe($release->status()) . '__' . $this->safe($release->releaseId()) . '.json', [
            'releaseId' => $release->releaseId(), 'status' => $release->status(),
        ]);
        foreach ($release->patchIds() as $patchId) {
            $this->writeJson($this->root . '/index/by-patch/' . $this->safe($patchId) . '.json', [
                'releaseId' => $release->releaseId(), 'patchId' => $patchId,
            ]);
        }
    }

    public function findRelease(string $id): ?ReleaseRecord
    {
        $data = $this->readJson($this->root . '/releases/' . $this->safe($id) . '/release.json');
        return $data !== null ? ReleaseRecord::fromArray($data) : null;
    }

    public function listReleases(?string $status = null): array
    {
        $out = [];
        foreach (glob($this->root . '/releases/*/release.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null) { continue; }
            $r = ReleaseRecord::fromArray($data);
            if ($status !== null && $status !== '' && $r->status() !== $status) { continue; }
            $out[] = $r;
        }
        usort($out, static fn (ReleaseRecord $a, ReleaseRecord $b): int => strcmp($b->toArray()['updatedAtUtc'], $a->toArray()['updatedAtUtc']));
        return $out;
    }

    public function findReleaseByPatch(string $patchId): ?ReleaseRecord
    {
        $idx = $this->readJson($this->root . '/index/by-patch/' . $this->safe($patchId) . '.json');
        if ($idx === null || !is_string($idx['releaseId'] ?? null)) { return null; }
        return $this->findRelease($idx['releaseId']);
    }

    public function saveEnvironment(Environment $env): void
    {
        $this->writeJson($this->root . '/environments/' . $this->safe($env->environmentId()) . '.json', $env->toArray());
    }

    public function listEnvironments(): array
    {
        $out = [];
        foreach (glob($this->root . '/environments/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = Environment::fromArray($data); }
        }
        usort($out, static fn (Environment $a, Environment $b): int => $a->promotionOrder() <=> $b->promotionOrder());
        return $out;
    }

    public function findEnvironment(string $id): ?Environment
    {
        $data = $this->readJson($this->root . '/environments/' . $this->safe($id) . '.json');
        return $data !== null ? Environment::fromArray($data) : null;
    }

    public function saveTarget(DeploymentTarget $target): void
    {
        $this->writeJson($this->root . '/targets/' . $this->safe($target->targetId()) . '.json', $target->toArray());
    }

    public function listTargets(?string $environmentId = null): array
    {
        $out = [];
        foreach (glob($this->root . '/targets/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null) { continue; }
            $t = DeploymentTarget::fromArray($data);
            if ($environmentId !== null && $environmentId !== '' && $t->environmentId() !== $environmentId) { continue; }
            $out[] = $t;
        }
        return $out;
    }

    public function saveDeployment(DeploymentPlan $plan): void
    {
        $this->writeJson($this->root . '/deployments/' . $this->safe($plan->deploymentId()) . '.json', $plan->toArray());
    }

    public function findDeployment(string $id): ?DeploymentPlan
    {
        $data = $this->readJson($this->root . '/deployments/' . $this->safe($id) . '.json');
        return $data !== null ? DeploymentPlan::fromArray($data) : null;
    }

    public function listDeployments(?string $releaseId = null): array
    {
        $out = [];
        foreach (glob($this->root . '/deployments/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null) { continue; }
            $d = DeploymentPlan::fromArray($data);
            if ($releaseId !== null && $releaseId !== '' && $d->releaseId() !== $releaseId) { continue; }
            $out[] = $d;
        }
        return $out;
    }

    public function saveRollback(RollbackPlan $plan): void
    {
        $this->writeJson($this->root . '/rollbacks/' . $this->safe($plan->rollbackId()) . '.json', $plan->toArray());
    }

    public function findRollback(string $id): ?RollbackPlan
    {
        $data = $this->readJson($this->root . '/rollbacks/' . $this->safe($id) . '.json');
        return $data !== null ? RollbackPlan::fromArray($data) : null;
    }

    public function listRollbacks(): array
    {
        $out = [];
        foreach (glob($this->root . '/rollbacks/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data !== null) { $out[] = RollbackPlan::fromArray($data); }
        }
        return $out;
    }

    public function appendEvent(GovernanceEvent $event): void
    {
        file_put_contents($this->root . '/events/timeline.jsonl', json_encode($event->toArray(), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    public function events(int $limit = 200): array
    {
        return $this->readJsonl($this->root . '/events/timeline.jsonl', $limit, static fn (array $d) => GovernanceEvent::fromArray($d));
    }

    public function appendAudit(AuditEntry $entry): void
    {
        file_put_contents($this->root . '/audit/timeline.jsonl', json_encode($entry->toArray(), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }

    public function audit(int $limit = 200): array
    {
        return $this->readJsonl($this->root . '/audit/timeline.jsonl', $limit, static fn (array $d) => AuditEntry::fromArray($d));
    }

    public function saveMetrics(array $metrics): void
    {
        $this->writeJson($this->root . '/metrics/snapshot.json', $metrics);
    }

    public function metrics(): array
    {
        return $this->readJson($this->root . '/metrics/snapshot.json') ?? [];
    }

    public function saveCompliance(array $compliance): void
    {
        $this->writeJson($this->root . '/compliance/latest.json', $compliance);
    }

    public function compliance(): array
    {
        return $this->readJson($this->root . '/compliance/latest.json') ?? ['pass' => true, 'violations' => []];
    }

    /** @param callable(array<string,mixed>):object $map */
    private function readJsonl(string $path, int $limit, callable $map): array
    {
        if (!is_file($path)) { return []; }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) { $items[] = $map($data); }
            if (count($items) >= $limit) { break; }
        }
        return $items;
    }
}
