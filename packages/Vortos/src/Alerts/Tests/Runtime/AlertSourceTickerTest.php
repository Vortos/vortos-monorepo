<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Runtime;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Alerts\Integration\AlertSourceInterface;
use Vortos\Alerts\Runtime\AlertSourceTicker;

final class AlertSourceTickerTest extends TestCase
{
    public function test_ticks_every_source_and_collects_their_results(): void
    {
        $a = new SpySource(['a1']);
        $b = new SpySource(['b1', 'b2']);

        $results = (new AlertSourceTicker([$a, $b]))->tick('prod', new DateTimeImmutable());

        self::assertSame(1, $a->ticks);
        self::assertSame(1, $b->ticks);
        self::assertSame(['a1', 'b1', 'b2'], $results);
    }

    public function test_passes_env_and_time_through_to_each_source(): void
    {
        $source = new SpySource([]);
        $now = new DateTimeImmutable('2026-07-26 12:00:00');

        (new AlertSourceTicker([$source]))->tick('staging', $now);

        self::assertSame('staging', $source->env);
        self::assertSame($now, $source->now);
    }

    public function test_a_throwing_source_does_not_stop_the_ones_after_it(): void
    {
        $exploding = new SpySource([], new RuntimeException('probe unavailable'));
        $healthy = new SpySource(['still-fired']);

        $results = (new AlertSourceTicker([$exploding, $healthy]))->tick('prod', new DateTimeImmutable());

        self::assertSame(1, $healthy->ticks, 'A failing disk probe must never suppress the DLQ alert evaluated after it.');
        self::assertSame(['still-fired'], $results);
    }

    public function test_no_sources_is_not_an_error(): void
    {
        self::assertSame([], (new AlertSourceTicker([]))->tick('prod', new DateTimeImmutable()));
    }
}

final class SpySource implements AlertSourceInterface
{
    public int $ticks = 0;
    public ?string $env = null;
    public ?DateTimeImmutable $now = null;

    /** @param list<mixed> $results */
    public function __construct(
        private readonly array $results,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function tick(string $env, DateTimeImmutable $now): array
    {
        $this->ticks++;
        $this->env = $env;
        $this->now = $now;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->results;
    }
}
