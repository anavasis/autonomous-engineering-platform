<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Knowledge;

use Aep\Application\Knowledge\Model\KnowledgeRecord;
use Aep\Infrastructure\Knowledge\Store\FilesystemKnowledgeStore;
use Tests\Support\Assert;

final class FilesystemKnowledgeStoreTest
{
    public function test_save_find_index_timeline_and_edges(): void
    {
        $root = sys_get_temp_dir() . '/aep_knw_store_' . bin2hex(random_bytes(4));
        try {
            $store = new FilesystemKnowledgeStore($root);
            $record = (new KnowledgeRecord(
                'knw_test1',
                'stable_abc',
                KnowledgeRecord::KIND_ENGINEERING,
                KnowledgeRecord::STATUS_ACTIVE,
                'project',
                'Title engineering knowledge',
                'Summary engineering',
                'Body about engineering knowledge',
                '2026-01-01T00:00:00Z',
                '2026-01-01T00:00:00Z',
                'prj_1',
                'msn_1',
            ))->withSealedHashes();
            $store->save($record);
            $store->appendTimeline(['atUtc' => '2026-01-01T00:00:00Z', 'knowledgeId' => 'knw_test1', 'type' => 'captured']);
            $store->addEdge([
                'fromType' => 'knowledge',
                'fromId' => 'knw_test1',
                'rel' => 'derived_from',
                'toType' => 'mission',
                'toId' => 'msn_1',
            ]);
            $store->saveEmbedding('local_lexical', 'knw_test1', [0.1, 0.2, 0.3]);

            $found = $store->find('knw_test1');
            Assert::true($found !== null);
            Assert::same('knw_test1', $found?->knowledgeId());
            Assert::true(str_starts_with($found?->integrityHash() ?? '', 'sha256:'));
            Assert::true(count($store->listByMission('msn_1')) >= 1);
            Assert::true(count($store->list('prj_1')) >= 1);
            Assert::true(count($store->timeline('knw_test1')) >= 1);
            Assert::true(count($store->edges('knw_test1')) >= 1);
            Assert::same([0.1, 0.2, 0.3], $store->loadEmbedding('local_lexical', 'knw_test1'));
            Assert::same('knw_test1', $store->findByStableKey('stable_abc')?->knowledgeId());
        } finally {
            $this->removeDir($root);
        }
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
