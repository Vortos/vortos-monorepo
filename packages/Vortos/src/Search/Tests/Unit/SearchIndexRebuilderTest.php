<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Backfill\SearchBackfillSourceInterface;
use Vortos\Search\Backfill\SearchIndexRebuilder;
use Vortos\Search\Document\SearchDocument;
use Vortos\Search\Index\SearchIndexWriterInterface;

final class SearchIndexRebuilderTest extends TestCase
{
    public function testRebuildsRegisteredTypeThroughWriter(): void
    {
        $writer = new RecordingWriter();
        $rebuilder = new SearchIndexRebuilder($writer, [
            $this->source('application', ['1', '2', '3']),
        ]);

        $count = $rebuilder->rebuildType('application');

        self::assertSame(3, $count);
        self::assertCount(3, $writer->upserts);
    }

    public function testFreshRebuildRequiresTenant(): void
    {
        $rebuilder = new SearchIndexRebuilder(new RecordingWriter(), [$this->source('application', ['1'])]);

        $this->expectException(\InvalidArgumentException::class);
        $rebuilder->rebuildType('application', fresh: true);
    }

    public function testFreshRebuildPurgesThenWrites(): void
    {
        $writer = new RecordingWriter();
        $rebuilder = new SearchIndexRebuilder($writer, [$this->source('application', ['1', '2'])]);

        $rebuilder->rebuildType('application', tenantId: 'org-1', fresh: true);

        self::assertSame([['application', 'org-1']], $writer->purges);
        self::assertCount(2, $writer->upserts);
    }

    public function testUnknownTypeThrows(): void
    {
        $rebuilder = new SearchIndexRebuilder(new RecordingWriter(), [$this->source('application', ['1'])]);

        $this->expectException(\InvalidArgumentException::class);
        $rebuilder->rebuildType('nope');
    }

    public function testRebuildAllCoversEveryType(): void
    {
        $rebuilder = new SearchIndexRebuilder(new RecordingWriter(), [
            $this->source('application', ['1', '2']),
            $this->source('payment', ['9']),
        ]);

        self::assertSame(['application' => 2, 'payment' => 1], $rebuilder->rebuildAll());
    }

    /** @param list<string> $ids */
    private function source(string $type, array $ids): SearchBackfillSourceInterface
    {
        return new class ($type, $ids) implements SearchBackfillSourceInterface {
            /** @param list<string> $ids */
            public function __construct(private string $type, private array $ids)
            {
            }
            public function type(): string
            {
                return $this->type;
            }
            public function documents(?string $tenantId = null): iterable
            {
                foreach ($this->ids as $id) {
                    yield new SearchDocument($this->type, $id, $tenantId ?? 'org-1', "title {$id}");
                }
            }
        };
    }
}

final class RecordingWriter implements SearchIndexWriterInterface
{
    /** @var list<SearchDocument> */
    public array $upserts = [];
    /** @var list<array{string, string}> */
    public array $purges = [];

    public function upsert(SearchDocument $document): void
    {
        $this->upserts[] = $document;
    }

    public function delete(string $type, string $entityId, string $tenantId): void
    {
    }

    public function purgeType(string $type, string $tenantId): int
    {
        $this->purges[] = [$type, $tenantId];
        return 0;
    }
}
