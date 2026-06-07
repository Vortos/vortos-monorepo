<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Bus;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Bus\StandaloneEventBus;
use Vortos\Messaging\Contract\EventBusInterface;

final class StandaloneEventBusTest extends TestCase
{
    public function test_dispatch_opens_own_transaction_when_none_is_active(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt-1',
            aggregateId: 'agg-1',
            aggregateType: 'Aggregate',
            aggregateVersion: 1,
            payloadType: \stdClass::class,
            schemaVersion: 1,
            occurredAt: new \DateTimeImmutable(),
            payload: new \stdClass(),
            metadata: new Metadata(),
        );
        $inner = $this->createMock(EventBusInterface::class);
        $inner->expects($this->once())->method('dispatch')->with($envelope);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
            ->willReturnCallback(static function (callable $callback): mixed {
                $callback();
                return null;
            });

        (new StandaloneEventBus($connection, $inner))->dispatch($envelope);
    }
}
