<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Tagging;

use PHPUnit\Framework\TestCase;
use Vortos\Release\Tagging\AppliedTag;
use Vortos\Release\Tagging\File\FileTaggingTransactionStore;
use Vortos\Release\Tagging\TaggingStatus;
use Vortos\Release\Tagging\TaggingTransaction;

final class FileTaggingTransactionStoreTest extends TestCase
{
    private string $tempDir;
    private FileTaggingTransactionStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vortos-tx-test-' . bin2hex(random_bytes(8));
        $this->store = new FileTaggingTransactionStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tempDir);
    }

    public function test_save_and_load(): void
    {
        $tx = new TaggingTransaction(
            id: 'tx-001',
            createdAt: new \DateTimeImmutable('2026-06-23T12:00:00+00:00'),
            tags: [
                new AppliedTag('vortos/vortos-release', 'v1.0.0-alpha-161', 'abc123', true),
            ],
            status: TaggingStatus::Complete,
        );

        $this->store->save($tx);
        $loaded = $this->store->load('tx-001');

        $this->assertNotNull($loaded);
        $this->assertSame('tx-001', $loaded->id);
        $this->assertSame(TaggingStatus::Complete, $loaded->status);
        $this->assertCount(1, $loaded->tags);
        $this->assertSame('vortos/vortos-release', $loaded->tags[0]->packageName);
        $this->assertTrue($loaded->tags[0]->pushed);
    }

    public function test_load_nonexistent(): void
    {
        $this->assertNull($this->store->load('does-not-exist'));
    }

    public function test_list_empty(): void
    {
        $this->assertSame([], $this->store->list());
    }

    public function test_list_multiple(): void
    {
        foreach (['tx-a', 'tx-b', 'tx-c'] as $id) {
            $tx = new TaggingTransaction(
                id: $id,
                createdAt: new \DateTimeImmutable(),
                tags: [],
                status: TaggingStatus::Planned,
            );
            $this->store->save($tx);
        }

        $list = $this->store->list();
        $this->assertCount(3, $list);
    }

    public function test_save_overwrites_same_id(): void
    {
        $tx = new TaggingTransaction(
            id: 'tx-overwrite',
            createdAt: new \DateTimeImmutable(),
            tags: [],
            status: TaggingStatus::Planned,
        );
        $this->store->save($tx);

        $tx->markComplete();
        $this->store->save($tx);

        $loaded = $this->store->load('tx-overwrite');
        $this->assertNotNull($loaded);
        $this->assertSame(TaggingStatus::Complete, $loaded->status);
    }

    public function test_round_trip_serialization(): void
    {
        $tx = new TaggingTransaction(
            id: 'tx-round',
            createdAt: new \DateTimeImmutable('2026-06-23T10:00:00+00:00'),
            tags: [
                new AppliedTag('pkg-a', 'v1.0.0', 'sha1', false),
                new AppliedTag('pkg-b', 'v1.0.0', 'sha2', true),
            ],
            status: TaggingStatus::Partial,
        );

        $this->store->save($tx);
        $loaded = $this->store->load('tx-round');

        $this->assertNotNull($loaded);
        $this->assertSame(TaggingStatus::Partial, $loaded->status);
        $this->assertCount(2, $loaded->tags);
        $this->assertFalse($loaded->tags[0]->pushed);
        $this->assertTrue($loaded->tags[1]->pushed);
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->cleanup($file) : unlink($file);
        }

        rmdir($dir);
    }
}
