<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Vortos\Search\Document\SearchDocument;
use Vortos\Search\Index\Dbal\DbalSearchIndexWriter;
use Vortos\Search\Index\Dbal\DbalSearchReader;
use Vortos\Search\Index\PortableLikeSearchDriver;
use Vortos\Search\Query\SearchQuery;
use Vortos\Search\Query\SearchScope;

/**
 * End-to-end on a real (sqlite) DB with the portable driver: writer → table → reader, exercising
 * the two scoping axes (org boundary, member visibility) + the permission gate, type filter,
 * ranking, idempotent upsert and delete. Proves the engine's guarantees without Postgres.
 */
final class DbalSearchScopingTest extends TestCase
{
    private Connection $connection;
    private DbalSearchIndexWriter $writer;
    private DbalSearchReader $reader;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema();

        $this->writer = new DbalSearchIndexWriter($this->connection);
        $this->reader = new DbalSearchReader($this->connection, new PortableLikeSearchDriver());
    }

    public function testOrgIsolation(): void
    {
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'Sarah Johnson'));
        $this->writer->upsert($this->doc('application', 'a2', 'org-2', 'Sarah Johnson'));

        $hits = $this->search('Sarah', new SearchScope('org-1'));

        self::assertCount(1, $hits);
        self::assertSame('a1', $hits[0]->entityId);
    }

    public function testPersonalRowVisibleOnlyToOwner(): void
    {
        // A personal document (owner set) plus an org-shared one.
        $this->writer->upsert($this->doc('note', 'n1', 'org-1', 'Sarah private note', ownerMemberId: 'member-A'));
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'Sarah application'));

        $owner  = $this->search('Sarah', new SearchScope('org-1', 'member-A'));
        $other  = $this->search('Sarah', new SearchScope('org-1', 'member-B'));
        $noUser = $this->search('Sarah', new SearchScope('org-1'));

        self::assertEqualsCanonicalizing(['n1', 'a1'], array_map(fn ($h) => $h->entityId, $owner));
        self::assertSame(['a1'], array_map(fn ($h) => $h->entityId, $other));
        self::assertSame(['a1'], array_map(fn ($h) => $h->entityId, $noUser));
    }

    public function testPermissionGateOnOrgSharedRows(): void
    {
        $this->writer->upsert($this->doc('payment', 'p1', 'org-1', 'Sarah payment', permission: 'payments.view.any'));
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'Sarah application')); // ungated

        $without = $this->search('Sarah', new SearchScope('org-1', 'm', []));
        $with    = $this->search('Sarah', new SearchScope('org-1', 'm', ['payments.view.any']));
        $super   = $this->search('Sarah', new SearchScope('org-1', 'm', [], superuser: true));

        self::assertSame(['a1'], array_map(fn ($h) => $h->entityId, $without));
        self::assertEqualsCanonicalizing(['p1', 'a1'], array_map(fn ($h) => $h->entityId, $with));
        self::assertEqualsCanonicalizing(['p1', 'a1'], array_map(fn ($h) => $h->entityId, $super));
    }

    public function testTypeFilter(): void
    {
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'Sarah'));
        $this->writer->upsert($this->doc('payment', 'p1', 'org-1', 'Sarah'));

        $hits = $this->reader->search(new SearchQuery('Sarah', ['payment']), new SearchScope('org-1'))->hits;

        self::assertSame(['p1'], array_map(fn ($h) => $h->entityId, $hits));
    }

    public function testTitleMatchRanksAboveBodyMatch(): void
    {
        $this->writer->upsert($this->doc('application', 'body', 'org-1', 'Unrelated', body: 'mentions cup deep in text'));
        $this->writer->upsert($this->doc('application', 'title', 'org-1', 'Cup Final'));

        $hits = $this->search('cup', new SearchScope('org-1'));

        self::assertSame('title', $hits[0]->entityId, 'the title match should rank first');
    }

    public function testUpsertIsIdempotentByNaturalKey(): void
    {
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'First'));
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'Second'));

        $hits = $this->search('Second', new SearchScope('org-1'));
        self::assertCount(1, $hits);
        self::assertSame('Second', $hits[0]->title);
        self::assertSame([], $this->search('First', new SearchScope('org-1')));
    }

    public function testDeleteRemovesRow(): void
    {
        $this->writer->upsert($this->doc('application', 'a1', 'org-1', 'Sarah'));
        $this->writer->delete('application', 'a1', 'org-1');

        self::assertSame([], $this->search('Sarah', new SearchScope('org-1')));
    }

    public function testDeeplinkAndMetaRoundTrip(): void
    {
        $this->writer->upsert(new SearchDocument(
            type: 'application',
            entityId: 'a1',
            tenantId: 'org-1',
            title: 'Sarah',
            deeplink: '/applications/a1',
            meta: ['status' => 'pending'],
        ));

        $hit = $this->search('Sarah', new SearchScope('org-1'))[0];
        self::assertSame('/applications/a1', $hit->deeplink);
        self::assertSame('pending', $hit->meta['status']);
    }

    /** @return list<\Vortos\Search\Query\SearchHit> */
    private function search(string $terms, SearchScope $scope): array
    {
        return $this->reader->search(new SearchQuery($terms), $scope)->hits;
    }

    private function doc(
        string $type,
        string $entityId,
        string $tenantId,
        string $title,
        string $body = '',
        ?string $permission = null,
        ?string $ownerMemberId = null,
    ): SearchDocument {
        return new SearchDocument(
            type: $type,
            entityId: $entityId,
            tenantId: $tenantId,
            title: $title,
            body: $body,
            permission: $permission,
            ownerMemberId: $ownerMemberId,
        );
    }

    private function createSchema(): void
    {
        $provider = require __DIR__ . '/../../Resources/migrations/20260718000001_create_search_documents.php';

        $schema = new Schema();
        $provider->define($schema);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
