<?php
declare(strict_types=1);

namespace Vortos\Tests\Cqrs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cqrs\Exception\QueryHandlerNotFoundException;
use Vortos\Cqrs\Query\QueryBus;
use Vortos\Domain\Query\QueryInterface;

final class BusTestQuery implements QueryInterface
{
    public function __construct(public readonly string $userId = 'user-1') {}
}

final class QueryBusTest extends TestCase
{
    public function test_dispatches_query_and_returns_result(): void
    {
        $handler = new class {
            public function __invoke(BusTestQuery $query): array
            {
                return ['id' => $query->userId];
            }
        };

        $locator = new ServiceLocator([BusTestQuery::class => fn() => $handler]);
        $bus = new QueryBus($locator);
        $result = $bus->ask(new BusTestQuery('user-1'));

        $this->assertSame(['id' => 'user-1'], $result);
    }

    public function test_throws_when_no_handler(): void
    {
        $locator = new ServiceLocator([]);
        $bus = new QueryBus($locator);
        $this->expectException(QueryHandlerNotFoundException::class);
        $bus->ask(new BusTestQuery());
    }
}
