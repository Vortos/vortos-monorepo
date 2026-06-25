<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime\Driver\BetterStack;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Tests\Fixtures\InMemoryBetterStackTransport;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackClient;
use Vortos\Health\Uptime\Exception\MonitorSyncException;
use Vortos\Health\Uptime\Exception\UptimeMonitorException;

final class BetterStackClientTest extends TestCase
{
    private const TOKEN_ENV_VAR = 'TEST_BETTERSTACK_TOKEN';

    protected function tearDown(): void
    {
        putenv(self::TOKEN_ENV_VAR);
        unset($_ENV[self::TOKEN_ENV_VAR]);
    }

    public function testCreateOrUpdateMonitorThrowsWhenTokenMissing(): void
    {
        $client = new BetterStackClient(new InMemoryBetterStackTransport(), self::TOKEN_ENV_VAR);

        $this->expectException(MonitorSyncException::class);
        $this->expectExceptionMessageMatches('/missing API token/');

        $client->createOrUpdateMonitor('key-1', []);
    }

    public function testCreateOrUpdateMonitorReturnsIdOnSuccess(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'secret-token-abc';
        $transport = (new InMemoryBetterStackTransport())->withResponse([
            'status' => 200,
            'body' => json_encode(['data' => ['id' => 'mon-123']]),
        ]);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        $id = $client->createOrUpdateMonitor('key-1', ['monitor_type' => 'multistep']);

        self::assertSame('mon-123', $id);
        self::assertSame('Bearer secret-token-abc', $transport->lastRequest()['headers']['Authorization']);
    }

    public function testCreateOrUpdateMonitorThrowsWithRedactedTokenOnHttpFailure(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'secret-token-abc';
        $transport = (new InMemoryBetterStackTransport())->withResponse([
            'status' => 401,
            'body' => 'Unauthorized: token secret-token-abc is invalid',
        ]);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        try {
            $client->createOrUpdateMonitor('key-1', []);
            self::fail('Expected MonitorSyncException.');
        } catch (MonitorSyncException $e) {
            self::assertStringNotContainsString('secret-token-abc', $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function testCreateOrUpdateMonitorThrowsOnMalformedResponse(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'secret-token-abc';
        $transport = (new InMemoryBetterStackTransport())->withResponse(['status' => 200, 'body' => 'not json']);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        $this->expectException(MonitorSyncException::class);

        $client->createOrUpdateMonitor('key-1', []);
    }

    public function testMonitorStatusReturnsDecodedBodyOnSuccess(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'secret-token-abc';
        $transport = (new InMemoryBetterStackTransport())->withResponse([
            'status' => 200,
            'body' => json_encode(['data' => ['attributes' => ['status' => 'up']]]),
        ]);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        $raw = $client->monitorStatus('mon-123');

        self::assertSame('up', $raw['data']['attributes']['status']);
    }

    public function testMonitorStatusThrowsWithRedactedTokenOnHttpFailure(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'secret-token-abc';
        $transport = (new InMemoryBetterStackTransport())->withResponse([
            'status' => 500,
            'body' => 'internal error, auth was secret-token-abc',
        ]);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        try {
            $client->monitorStatus('mon-123');
            self::fail('Expected UptimeMonitorException.');
        } catch (UptimeMonitorException $e) {
            self::assertStringNotContainsString('secret-token-abc', $e->getMessage());
        }
    }

    public function testMonitorStatusThrowsOnMalformedJson(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'secret-token-abc';
        $transport = (new InMemoryBetterStackTransport())->withResponse(['status' => 200, 'body' => 'not json']);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        $this->expectException(UptimeMonitorException::class);

        $client->monitorStatus('mon-123');
    }

    public function testMonitorStatusThrowsWhenTokenMissing(): void
    {
        $client = new BetterStackClient(new InMemoryBetterStackTransport(), self::TOKEN_ENV_VAR);

        $this->expectException(UptimeMonitorException::class);

        $client->monitorStatus('mon-123');
    }
}
