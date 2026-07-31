<?php

declare(strict_types=1);

namespace Tests\Application\Knowledge;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Application\Knowledge\Model\RetrievalQuery;
use Aep\Application\Knowledge\Policy\KnowledgeRetrievalPolicyFactory;
use Aep\Application\Knowledge\Service\ArchivePolicy;
use Aep\Application\Knowledge\Service\ForgetPolicy;
use Aep\Application\Knowledge\Service\KnowledgeCaptureService;
use Aep\Application\Knowledge\Service\KnowledgeGraph;
use Aep\Application\Knowledge\Service\KnowledgeRanker;
use Aep\Application\Knowledge\Service\KnowledgeRetrievalService;
use Aep\Infrastructure\Knowledge\Embedding\LocalLexicalEmbeddingProvider;
use Aep\Infrastructure\Knowledge\Provider\ConfigEmbeddingProviderRegistry;
use Aep\Infrastructure\Knowledge\Store\FilesystemKnowledgeStore;
use Aep\Infrastructure\Knowledge\Store\JsonKnowledgeSettingsStore;
use Tests\Support\Assert;

final class KnowledgePipelineServiceTest
{
    public function test_capture_produces_fingerprint_integrity_and_provenance(): void
    {
        $root = sys_get_temp_dir() . '/aep_knw_' . bin2hex(random_bytes(4));
        try {
            $capture = $this->capture($root);
            $record = $capture->capture([
                'kind' => KnowledgeRecord::KIND_LESSON,
                'title' => 'Prefer workspace fingerprints',
                'summary' => 'Seal workspaces before patch review',
                'body' => 'Engineering lesson about workspace fingerprints and patch validation',
                'projectId' => 'prj_1',
                'missionId' => 'msn_1',
                'tags' => ['workspace', 'patch'],
                'sourceEvents' => [['eventType' => 'manual', 'atUtc' => '2026-01-01T00:00:00Z']],
                'sourceArtifacts' => ['artifact:report.1'],
                'provenance' => ['plane' => 'manual'],
            ], 'tester');

            Assert::true(str_starts_with($record->knowledgeId(), 'knw_'));
            Assert::same(1, $record->toArray()['schemaVersion'] ?? null);
            Assert::true(str_starts_with($record->reproducibilityFingerprint(), 'sha256:'));
            Assert::true(str_starts_with($record->integrityHash(), 'sha256:'));
            Assert::same('tester', $record->toArray()['capturedBy'] ?? null);
            Assert::same(KnowledgeCaptureService::CAPTURE_VERSION, $record->toArray()['captureVersion'] ?? null);
            Assert::true(isset($record->toArray()['compatibility']['aepMinVersion']));
            Assert::true(count($record->toArray()['sourceEvents']) >= 1);
            Assert::true(count($record->toArray()['sourceArtifacts']) >= 1);
            Assert::true(is_array($record->toArray()['provenance']));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_retrieval_is_policy_driven(): void
    {
        $root = sys_get_temp_dir() . '/aep_knw_' . bin2hex(random_bytes(4));
        try {
            $capture = $this->capture($root);
            $capture->capture([
                'kind' => KnowledgeRecord::KIND_MISSION,
                'title' => 'Mission about patch validation pipeline',
                'summary' => 'Completed patch validation for engineering workspace',
                'body' => 'patch validation workspace engineering success pattern',
                'projectId' => 'prj_x',
                'missionId' => 'msn_x',
                'tags' => ['patch', 'validation'],
            ], 'tester');
            $retrieval = $this->retrieval($root);
            $pack = $retrieval->retrieve(new RetrievalQuery(
                'patch validation engineering workspace',
                'prj_x',
                null,
                [],
                [],
                [],
                [],
                8,
                'preview',
            ));
            $data = $pack->toArray();
            Assert::true(count($data['hits']) >= 1);
            Assert::true(count($data['policyTrace']) >= 1);
            Assert::true(str_starts_with($data['fingerprint'], 'sha256:'));
            $policyIds = [];
            foreach ($data['policyTrace'] as $row) {
                if (is_array($row) && is_string($row['policy'] ?? null)) {
                    $policyIds[] = $row['policy'];
                }
            }
            Assert::true(in_array('lexical_overlap', $policyIds, true));
            Assert::true(in_array('require_active_status', $policyIds, true));
        } finally {
            $this->removeDir($root);
        }
    }

    public function test_archive_and_forget(): void
    {
        $root = sys_get_temp_dir() . '/aep_knw_' . bin2hex(random_bytes(4));
        try {
            $capture = $this->capture($root);
            $record = $capture->capture([
                'kind' => KnowledgeRecord::KIND_CONSTRAINT,
                'title' => 'Do not commit secrets',
                'summary' => 'Constraint memory',
                'body' => 'Never commit api keys in diffs',
                'projectId' => 'prj_z',
            ], 'tester');
            $archived = $capture->archive($record->knowledgeId());
            Assert::same(KnowledgeRecord::STATUS_ARCHIVED, $archived?->status());
            $forgotten = $capture->forget($record->knowledgeId());
            Assert::same(KnowledgeRecord::STATUS_FORGOTTEN, $forgotten?->status());
            Assert::same('[forgotten]', $forgotten?->body());
        } finally {
            $this->removeDir($root);
        }
    }

    private function capture(string $root): KnowledgeCaptureService
    {
        $settings = new JsonKnowledgeSettingsStore($root);
        $store = new FilesystemKnowledgeStore($root);
        $registry = new ConfigEmbeddingProviderRegistry([
            'default' => 'local_lexical',
            'providers' => [['id' => 'local_lexical', 'type' => 'local_lexical', 'enabled' => true]],
        ], [
            'local_lexical' => static fn (array $o): LocalLexicalEmbeddingProvider => new LocalLexicalEmbeddingProvider(),
        ]);

        return new KnowledgeCaptureService(
            $store,
            $settings,
            new KnowledgeGraph($store),
            $registry,
            new ArchivePolicy(),
            new ForgetPolicy(),
        );
    }

    private function retrieval(string $root): KnowledgeRetrievalService
    {
        $settings = new JsonKnowledgeSettingsStore($root);
        $store = new FilesystemKnowledgeStore($root);
        $registry = new ConfigEmbeddingProviderRegistry([
            'default' => 'local_lexical',
            'providers' => [['id' => 'local_lexical', 'type' => 'local_lexical', 'enabled' => true]],
        ]);
        $policies = KnowledgeRetrievalPolicyFactory::fromSettings($settings->get());

        return new KnowledgeRetrievalService(
            $store,
            $settings,
            new KnowledgeRanker($policies),
            $registry,
            new KnowledgeGraph($store),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
