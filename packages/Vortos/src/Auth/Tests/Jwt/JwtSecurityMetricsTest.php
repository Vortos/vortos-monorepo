<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Jwt;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Exception\TokenReusedException;
use Vortos\Auth\Exception\TokenRevokedException;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\Key\KeyStatus;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Jwt\Key\SigningKey;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * The token-reuse (theft) and revoke-on-refresh events are security signals — they must be
 * observable, not just thrown. These pin that JwtService emits the security-events counter with
 * the right event label so dashboards/alerts can be built on them.
 */
final class JwtSecurityMetricsTest extends TestCase
{
    private const SECRET = 'metrics-secret-ffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    public function test_reuse_emits_theft_metric(): void
    {
        $metrics = new RecordingMetrics();
        $service = $this->service($metrics);
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $token = $service->issue($identity);
        $service->refresh($token->refreshToken, $identity);

        try { $service->refresh($token->refreshToken, $identity); } catch (TokenReusedException) {}

        $this->assertContains(
            ['security_events_total', 'auth.token.reuse_detected'],
            $metrics->events(),
        );
    }

    public function test_revoke_on_refresh_emits_metric(): void
    {
        $metrics = new RecordingMetrics();
        $storage = new InMemoryTokenStorage();
        $service = $this->service($metrics, $storage);
        $identity = new UserIdentity('user-1', ['ROLE_USER']);

        $token = $service->issue($identity);
        $storage->revoke($this->jti($token->refreshToken));

        try { $service->refresh($token->refreshToken, $identity); } catch (TokenRevokedException) {}

        $this->assertContains(
            ['security_events_total', 'auth.session.revoked_on_refresh'],
            $metrics->events(),
        );
    }

    private function service(MetricsInterface $metrics, ?InMemoryTokenStorage $storage = null): JwtService
    {
        return new JwtService(
            new JwtConfig(
                new Keyring(SigningKey::hs256('key-1', self::SECRET, KeyStatus::Active)),
                issuer: 'test',
                audience: 'test',
            ),
            $storage ?? new InMemoryTokenStorage(),
            null,
            new FrameworkTelemetry($metrics),
        );
    }

    private function jti(string $refreshToken): string
    {
        $parts = explode('.', $refreshToken);
        $payload = (array) json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return (string) $payload['jti'];
    }
}

/** Records every counter(name, labels) call so tests can assert which security events fired. */
final class RecordingMetrics implements MetricsInterface
{
    /** @var list<array{name: string, labels: array<string, string>}> */
    public array $counters = [];

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $this->counters[] = ['name' => $name, 'labels' => $labels];
        return new class implements CounterInterface {
            public function increment(float $by = 1.0): void {}
        };
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        return new class implements GaugeInterface {
            public function set(float $value): void {}
            public function increment(float $by = 1.0): void {}
            public function decrement(float $by = 1.0): void {}
        };
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        return new class implements HistogramInterface {
            public function observe(float $value): void {}
        };
    }

    /** @return list<array{0: string, 1: string}> [metricName, eventLabel] pairs */
    public function events(): array
    {
        return array_map(
            fn (array $c) => [$c['name'], $c['labels']['event'] ?? ''],
            $this->counters,
        );
    }
}
