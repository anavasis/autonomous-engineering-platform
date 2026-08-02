<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Governance\Store;

use Aep\Application\Governance\Port\GovernanceSettingsStore;

final class JsonGovernanceSettingsStore implements GovernanceSettingsStore
{
    private readonly string $path;

    public function __construct(string $governanceRoot)
    {
        $root = rtrim($governanceRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create governance settings dir.');
        }
        $this->path = $root . '/settings.json';
        if (!is_file($this->path)) { $this->put($this->defaults()); }
    }

    public function get(): array
    {
        if (!is_file($this->path)) { return $this->defaults(); }
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? array_merge($this->defaults(), $data) : $this->defaults();
    }

    public function put(array $settings): array
    {
        $merged = array_merge($this->get(), $settings);
        $out = $this->defaults();
        foreach ($out as $key => $_) {
            if (array_key_exists($key, $merged)) { $out[$key] = $merged[$key]; }
        }
        if (isset($merged['approvalStages']) && is_array($merged['approvalStages'])) {
            $out['approvalStages'] = $merged['approvalStages'];
        }
        file_put_contents($this->path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return $out;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'enabled' => true,
            'engineeringGovernance' => true,
            'observePatchSeal' => true,
            'observePlanningLaunch' => false,
            'hardComplianceGate' => true,
            'requireGates' => true,
            'requireApprovals' => true,
            'requireIntegrityHash' => true,
            'requirePatchEvidence' => true,
            'requireSealedArtifacts' => false,
            'approvalStages' => [
                ['stageId' => 'engineering', 'name' => 'Engineering', 'minApprovals' => 1],
                ['stageId' => 'security', 'name' => 'Security', 'minApprovals' => 1],
            ],
        ];
    }
}
