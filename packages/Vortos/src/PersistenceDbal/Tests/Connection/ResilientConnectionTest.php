<?php

declare(strict_types=1);

namespace Vortos\PersistenceDbal\Tests\Connection;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceDbal\Connection\ConnectionFactory;
use Vortos\PersistenceDbal\Connection\ResilientConnection;

final class ResilientConnectionTest extends TestCase
{
    private float $now = 1000.0;

    /** @var list<array{dead: bool, queries: list<string>}> */
    private array $sockets = [];

    public function test_a_busy_socket_is_never_pinged(): void
    {
        $connection = $this->connection();

        $connection->executeQuery('SELECT 42');
        $this->now += 5;
        $connection->executeQuery('SELECT 43');

        self::assertCount(1, $this->sockets);
        self::assertSame(['SELECT 42', 'SELECT 43'], $this->sockets[0]['queries']);
    }

    public function test_an_idle_live_socket_is_pinged_and_kept(): void
    {
        $connection = $this->connection();

        $connection->executeQuery('SELECT 42');
        $this->now += 31;
        $connection->executeQuery('SELECT 43');

        self::assertCount(1, $this->sockets);
        self::assertSame(['SELECT 42', 'SELECT 1', 'SELECT 43'], $this->sockets[0]['queries']);
    }

    public function test_an_idle_dead_socket_is_replaced_before_the_query_runs(): void
    {
        $connection = $this->connection();

        $connection->executeQuery('SELECT 42');
        $this->sockets[0]['dead'] = true; // the server restarted while the worker was idle
        $this->now += 31;
        $connection->executeQuery('SELECT 43');

        self::assertCount(2, $this->sockets, 'a fresh socket was opened');
        self::assertSame(['SELECT 43'], $this->sockets[1]['queries']);
    }

    public function test_the_first_use_after_a_request_boundary_is_pinged_however_busy(): void
    {
        $connection = $this->connection();

        $connection->executeQuery('SELECT 42');
        $connection->reset();                     // end of the request
        $this->sockets[0]['dead'] = true;         // the server restarted between two busy requests
        $this->now += 1;
        $connection->executeQuery('SELECT 43');
        $connection->executeQuery('SELECT 44');   // within the same request: not pinged again

        self::assertCount(2, $this->sockets, 'replaced before the request used it');
        self::assertSame(['SELECT 43', 'SELECT 44'], $this->sockets[1]['queries']);
    }

    public function test_a_socket_inside_a_transaction_is_never_swapped(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->sockets[0]['dead'] = true;
        $this->now += 31;

        try {
            $connection->executeQuery('SELECT 43');
            self::fail('the dead transaction must surface, not continue in autocommit on a new socket');
        } catch (\Throwable) {
        }

        self::assertCount(1, $this->sockets);
    }

    public function test_reset_retires_an_old_connection_and_keeps_a_young_one(): void
    {
        $connection = $this->connection();

        $connection->executeQuery('SELECT 1');
        $this->now += 100;
        $connection->reset();
        self::assertTrue($connection->isConnected(), 'young: kept');

        $this->now += 3600;
        $connection->reset();
        self::assertFalse($connection->isConnected(), 'old: retired at the boundary');
    }

    public function test_reset_never_closes_inside_a_transaction(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->now += 7200;
        $connection->reset();

        self::assertTrue($connection->isConnected());
    }

    public function test_the_factory_builds_resilient_connections_and_side_connections_inherit_it(): void
    {
        $main = ConnectionFactory::fromDsn('pgsql://u:p@db:5432/app');
        self::assertInstanceOf(ResilientConnection::class, $main);

        // App code opens side connections from the main one's params (reference numbers, gate counters).
        $side = DriverManager::getConnection($main->getParams());
        self::assertInstanceOf(ResilientConnection::class, $side);
    }

    private function connection(): ResilientConnection
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturnCallback(function (): DriverConnection {
            $index           = count($this->sockets);
            $this->sockets[] = ['dead' => false, 'queries' => []];

            $socket = $this->createMock(DriverConnection::class);
            $run    = function (string $sql) use ($index): DriverResult {
                if ($this->sockets[$index]['dead']) {
                    throw new \RuntimeException('server closed the connection unexpectedly');
                }
                $this->sockets[$index]['queries'][] = $sql;

                return $this->createMock(DriverResult::class);
            };
            $socket->method('query')->willReturnCallback($run);
            $socket->method('beginTransaction')->willReturnCallback(function () use ($index): void {
                if ($this->sockets[$index]['dead']) {
                    throw new \RuntimeException('server closed the connection unexpectedly');
                }
            });

            return $socket;
        });

        $connection = new ResilientConnection([], $driver, new Configuration());

        return $connection->withClock(fn (): float => $this->now);
    }
}
