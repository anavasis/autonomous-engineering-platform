<?php

declare(strict_types=1);

namespace Aep\Infrastructure\CodeReview\Store;

use Aep\Application\CodeReview\Port\PatchSettingsStore;

final class JsonPatchSettingsStore implements PatchSettingsStore
{
    private readonly string $path;

    public function __construct(string $patchesRoot)
    {
        $root = rtrim($patchesRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create patches settings root.');
        }
        $this->path = $root . '/settings.json';
        if (!is_file($this->path)) {
            $this->write([
                'autoCreatePatchOnExecution' => true,
                'minScore' => 70,
                'reviewMode' => 'any',
                'reviewQuorum' => 1,
                'requireHumanApproval' => false,
                'reviewProviders' => ['heuristic-local'],
                'requiredCheckKinds' => ['diff', 'static', 'tests'],
                'ownership' => [
                    'src/Application' => 'platform',
                    'src/Domain' => 'platform',
                    'apps/mission-control-ui' => 'frontend',
                ],
            ]);
        }
    }

    public function get(): array
    {
        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) ? $data : [];
    }

    public function update(array $patch): array
    {
        $current = $this->get();
        foreach (['minScore', 'reviewQuorum'] as $intKey) {
            if (array_key_exists($intKey, $patch) && is_int($patch[$intKey])) {
                $current[$intKey] = $patch[$intKey];
            }
        }
        foreach (['autoCreatePatchOnExecution', 'requireHumanApproval'] as $boolKey) {
            if (array_key_exists($boolKey, $patch)) {
                $current[$boolKey] = $patch[$boolKey] === true;
            }
        }
        if (isset($patch['reviewMode']) && is_string($patch['reviewMode'])) {
            $current['reviewMode'] = $patch['reviewMode'];
        }
        if (isset($patch['reviewProviders']) && is_array($patch['reviewProviders'])) {
            $list = [];
            foreach ($patch['reviewProviders'] as $id) {
                if (is_string($id)) {
                    $list[] = $id;
                }
            }
            $current['reviewProviders'] = $list;
        }
        if (isset($patch['ownership']) && is_array($patch['ownership'])) {
            $own = [];
            foreach ($patch['ownership'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $own[$k] = $v;
                }
            }
            $current['ownership'] = $own;
        }
        $this->write($current);

        return $current;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        file_put_contents($this->path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
