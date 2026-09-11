<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\FeatureFlags\Exposure\ExposureSource;
use Vortos\FeatureFlags\Exposure\Ledger\DailyExposureDeduper;
use Vortos\FeatureFlags\Exposure\Ledger\LedgerExposure;

/**
 * What collapses per-request exposures into the ledger's per-day grain — and what happens
 * when the cache backing it is not there.
 */
final class DailyExposureDeduperTest extends TestCase
{
    public function test_the_first_exposure_of_the_day_is_new(): void
    {
        $deduper = new DailyExposureDeduper($this->cache(), $this->clock());

        self::assertTrue($deduper->isFirstToday($this->exposure()));
    }

    public function test_a_repeat_on_the_same_day_is_not(): void
    {
        $deduper = new DailyExposureDeduper($this->cache(), $this->clock());

        $deduper->isFirstToday($this->exposure());

        self::assertFalse($deduper->isFirstToday($this->exposure()));
    }

    public function test_a_different_variant_is_a_new_exposure(): void
    {
        $deduper = new DailyExposureDeduper($this->cache(), $this->clock());

        $deduper->isFirstToday($this->exposure(variant: 'true'));

        self::assertTrue($deduper->isFirstToday($this->exposure(variant: 'false')));
    }

    public function test_the_same_exposure_on_a_different_day_is_new(): void
    {
        $deduper = new DailyExposureDeduper($this->cache(), $this->clock());

        $deduper->isFirstToday($this->exposure(day: '2026-09-10'));

        self::assertTrue($deduper->isFirstToday($this->exposure(day: '2026-09-11')));
    }

    public function test_it_fails_open_when_the_cache_throws(): void
    {
        // Deliberate direction. A duplicate costs one wasted insert that ON CONFLICT absorbs;
        // a dropped exposure is an unrecoverable, invisible hole in an experiment.
        $deduper = new DailyExposureDeduper(
            new class implements AtomicCacheInterface {
                public function setNx(string $key, mixed $value, int $ttl): bool
                {
                    throw new RuntimeException('redis is down');
                }

                public function get(string $key, mixed $default = null): mixed { return $default; }
                public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool { return true; }
                public function delete(string $key): bool { return true; }
                public function clear(): bool { return true; }
                public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
                public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { return true; }
                public function deleteMultiple(iterable $keys): bool { return true; }
                public function has(string $key): bool { return false; }
            },
            $this->clock(),
        );

        self::assertTrue($deduper->isFirstToday($this->exposure()));
    }

    public function test_it_fails_open_when_no_cache_is_wired(): void
    {
        $deduper = new DailyExposureDeduper(null, $this->clock());

        self::assertTrue($deduper->isFirstToday($this->exposure()));
        self::assertTrue($deduper->isFirstToday($this->exposure()));
    }

    public function test_the_ttl_covers_the_rest_of_the_day_plus_grace(): void
    {
        $cache   = $this->cache();
        $deduper = new DailyExposureDeduper($cache, $this->clock('2026-09-11 23:59:59'));

        $deduper->isFirstToday($this->exposure());

        // A key minted a second before midnight must still be alive a second after it,
        // otherwise the first evaluation of the new day is counted twice — into two different
        // days, so the primary key does not catch it either.
        self::assertGreaterThanOrEqual(DailyExposureDeduper::TTL_GRACE_SECONDS, $cache->lastTtl);
    }

    public function test_keys_are_namespaced(): void
    {
        $cache = $this->cache();

        (new DailyExposureDeduper($cache, $this->clock()))->isFirstToday($this->exposure());

        self::assertStringStartsWith(DailyExposureDeduper::KEY_PREFIX, $cache->lastKey);
    }

    private function cache(): AtomicCacheInterface
    {
        return new class implements AtomicCacheInterface {
            /** @var array<string,true> */
            public array $keys = [];
            public string $lastKey = '';
            public int $lastTtl = 0;

            public function setNx(string $key, mixed $value, int $ttl): bool
            {
                $this->lastKey = $key;
                $this->lastTtl = $ttl;

                if (isset($this->keys[$key])) {
                    return false;
                }

                $this->keys[$key] = true;

                return true;
            }

            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool { return true; }
            public function delete(string $key): bool { return true; }
            public function clear(): bool { return true; }
            public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { return true; }
            public function deleteMultiple(iterable $keys): bool { return true; }
            public function has(string $key): bool { return isset($this->keys[$key]); }
        };
    }

    private function clock(string $now = '2026-09-11 12:00:00'): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            public function __construct(private readonly string $now) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
            }
        };
    }

    private function exposure(string $variant = 'true', string $day = '2026-09-11'): LedgerExposure
    {
        return new LedgerExposure(
            subjectHash: str_repeat('a', 32),
            flag:        'checkout',
            variant:     $variant,
            source:      ExposureSource::Server,
            groupKey:    'org-1',
            day:         $day,
            firstSeenAt: new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')),
        );
    }
}
