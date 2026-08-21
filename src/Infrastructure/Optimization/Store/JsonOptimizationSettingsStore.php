<?php
declare(strict_types=1);
namespace Aep\Infrastructure\Optimization\Store;

use Aep\Application\Optimization\Port\OptimizationSettingsStore;

final class JsonOptimizationSettingsStore implements OptimizationSettingsStore
{
    private readonly string $path;

    public function __construct(string $optimizationRoot)
    {
        $root = rtrim($optimizationRoot, "/\\");
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create optimization settings dir.');
        }
        $this->path = $root . '/settings.json';
        if (!is_file($this->path)) {
            $this->put($this->defaults());
        }
    }

    public function get(): array
    {
        if (!is_file($this->path)) {
            return $this->defaults();
        }
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? array_merge($this->defaults(), $data) : $this->defaults();
    }

    public function put(array $settings): array
    {
        $merged = array_merge($this->get(), $settings);
        $out = $this->defaults();
        foreach ($out as $key => $_) {
            if (array_key_exists($key, $merged)) {
                $out[$key] = $merged[$key];
            }
        }
        if (isset($merged['weights']) && is_array($merged['weights'])) {
            $out['weights'] = array_merge($out['weights'], $merged['weights']);
        }
        file_put_contents($this->path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return $out;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'enabled' => true,
            'resourceCostCapacityOptimization' => true,
            'mode' => 'balanced',
            'failOpen' => true,
            'hardBudgetGate' => false,
            'delayOnContention' => false,
            'allowOvercommit' => false,
            'assistPlanning' => true,
            'assistExecution' => true,
            'dailyBudgetLimit' => 100.0,
            'monthlyBudgetLimit' => 2000.0,
            'dailyRequestLimit' => 1000,
            'weights' => [
                'cost' => 10.0,
                'quality' => 12.0,
                'latency' => 8.0,
            ],
        ];
    }
}
