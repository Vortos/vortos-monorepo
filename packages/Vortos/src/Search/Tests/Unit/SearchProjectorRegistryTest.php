<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Document\SearchDocument;
use Vortos\Search\Projection\SearchableProjection;
use Vortos\Search\Projection\SearchDelete;
use Vortos\Search\Projection\SearchProjectorRegistry;
use Vortos\Search\Projection\SearchUpsert;

final class SearchProjectorRegistryTest extends TestCase
{
    public function testRoutesEventOnlyToSubscribedProjectors(): void
    {
        $registry = new SearchProjectorRegistry([
            $this->projector([FakeCreated::class], static fn ($e) => new SearchUpsert(
                new SearchDocument('application', $e->id, 'org-1', 'title'),
            )),
            $this->projector([FakeUnrelated::class], static fn ($e) => new SearchDelete('other', 'x', 'org-1')),
        ]);

        $outcomes = $registry->project(new FakeCreated('42'));

        self::assertCount(1, $outcomes);
        self::assertInstanceOf(SearchUpsert::class, $outcomes[0]);
        self::assertSame('42', $outcomes[0]->document->entityId);
    }

    public function testNullOutcomeIsSkipped(): void
    {
        $registry = new SearchProjectorRegistry([
            $this->projector([FakeCreated::class], static fn ($e) => null),
        ]);

        self::assertSame([], $registry->project(new FakeCreated('1')));
    }

    public function testHandlesReflectsSubscription(): void
    {
        $registry = new SearchProjectorRegistry([
            $this->projector([FakeCreated::class], static fn ($e) => null),
        ]);

        self::assertTrue($registry->handles(new FakeCreated('1')));
        self::assertFalse($registry->handles(new FakeUnrelated()));
    }

    public function testMatchesSubclassesOfSubscribedEvent(): void
    {
        $registry = new SearchProjectorRegistry([
            $this->projector([FakeCreated::class], static fn ($e) => new SearchDelete('t', $e->id, 'org-1')),
        ]);

        self::assertTrue($registry->handles(new FakeCreatedSubclass('9')));
        self::assertCount(1, $registry->project(new FakeCreatedSubclass('9')));
    }

    /**
     * @param list<class-string> $subscribes
     */
    private function projector(array $subscribes, callable $project): SearchableProjection
    {
        return new class ($subscribes, $project) implements SearchableProjection {
            /** @param list<class-string> $subscribes */
            public function __construct(private array $subscribes, private $project)
            {
            }

            public function subscribesTo(): array
            {
                return $this->subscribes;
            }

            public function project(object $event): SearchUpsert|SearchDelete|null
            {
                return ($this->project)($event);
            }
        };
    }
}

class FakeCreated
{
    public function __construct(public string $id)
    {
    }
}

class FakeCreatedSubclass extends FakeCreated
{
}

final class FakeUnrelated
{
}
