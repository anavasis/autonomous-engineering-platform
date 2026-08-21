<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Acceptance;

use Aep\Application\Acceptance\Model\AcceptanceReport;
use Aep\Application\Acceptance\Port\AcceptanceReportStore;

/**
 * Persists acceptance reports under {root}/reports/{id}.json
 * with index/by-project/{project}.json
 */
final class FilesystemAcceptanceReportStore implements AcceptanceReportStore
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, "/\\");
        foreach ([
            $this->root,
            $this->root . '/reports',
            $this->root . '/index/by-project',
        ] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create acceptance report dir: ' . $dir);
            }
        }
    }

    public function save(AcceptanceReport $report): void
    {
        $path = $this->root . '/reports/' . $this->safe($report->id()) . '.json';
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write acceptance report: ' . $path);
        }

        $indexPath = $this->root . '/index/by-project/' . $this->safe($report->projectName()) . '.json';
        $ids = [];
        if (is_file($indexPath)) {
            $existing = json_decode((string) file_get_contents($indexPath), true);
            if (is_array($existing) && is_array($existing['reportIds'] ?? null)) {
                foreach ($existing['reportIds'] as $id) {
                    if (is_string($id)) {
                        $ids[] = $id;
                    }
                }
            }
        }
        if (!in_array($report->id(), $ids, true)) {
            $ids[] = $report->id();
        }
        file_put_contents($indexPath, json_encode([
            'projectName' => $report->projectName(),
            'reportIds' => $ids,
            'updatedAtUtc' => $report->toArray()['createdAtUtc'] ?? '',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function get(string $reportId): ?AcceptanceReport
    {
        $path = $this->root . '/reports/' . $this->safe($reportId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        return AcceptanceReport::fromArray($data);
    }

    public function list(?string $projectName = null): array
    {
        if ($projectName !== null && $projectName !== '') {
            $indexPath = $this->root . '/index/by-project/' . $this->safe($projectName) . '.json';
            if (!is_file($indexPath)) {
                return [];
            }
            $existing = json_decode((string) file_get_contents($indexPath), true);
            $ids = is_array($existing) && is_array($existing['reportIds'] ?? null) ? $existing['reportIds'] : [];
            $out = [];
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $report = $this->get($id);
                if ($report !== null) {
                    $out[] = $report;
                }
            }

            return $out;
        }

        $files = glob($this->root . '/reports/*.json') ?: [];
        $out = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $out[] = AcceptanceReport::fromArray($data);
            }
        }
        usort(
            $out,
            static fn (AcceptanceReport $a, AcceptanceReport $b): int => strcmp(
                $b->toArray()['createdAtUtc'] ?? '',
                $a->toArray()['createdAtUtc'] ?? ''
            )
        );

        return $out;
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?? $id;
    }
}
