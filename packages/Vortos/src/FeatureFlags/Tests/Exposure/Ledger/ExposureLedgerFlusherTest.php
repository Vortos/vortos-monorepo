<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Vortos\FeatureFlags\Exposure\ExposureRecord;
use Vortos\FeatureFlags\Exposure\ExposureSource;
use Vortos\FeatureFlags\Exposure\Ledger\DailyExposureDeduper;
use Vortos\FeatureFlags\Exposure\Ledger\ExposureLedgerFlusher;
use Vortos\FeatureFlags\Exposure\Ledger\ExposureLedgerInterface;
use Vortos\FeatureFlags\Exposure\Ledger\LedgerExposure;
use Vortos\FeatureFlags\Exposure\Ledger\LedgerExposureObserver;
use Vortos\FeatureFlags\Exposure\Ledger\SubjectPseudonymiser;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * The post-response write. Everything here runs after the client already has its response,
 * which is the only reason the ledger can afford to be complete rather than sampled.
 */
final class ExposureLedgerFlusherTest extends TestCase
{
    private const PEPPER = 'e6f1c0aa8b2d4f7391c5ae0b7d2f48366a9c1e5b0d8342f7ac916be4d05f2371';

    public function test_it_writes_what_the_request_buffered(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $ledger = $this->ledger();
        $this->flusher($observer, $ledger)->flush();

        self::assertCount(1, $ledger->appended);
    }

    public function test_it_writes_nothing_when_the_request_saw_no_flags(): void
    {
        $ledger = $this->ledger();

        $this->flusher($this->observer(), $ledger)->flush();

        self::assertSame([], $ledger->appended);
    }

    public function test_it_drops_exposures_already_recorded_today(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $deduper = $this->deduper();
        $ledger  = $this->ledger();

        // First request writes it.
        $this->flusher($observer, $ledger, $deduper)->flush();

        // Second request, same subject and result, same day: the cross-request deduper is
        // what collapses this to the day grain. Without it the ledger would hold one row per
        // page view rather than one per subject per day.
        $observer->onExposure($this->record());
        $this->flusher($observer, $ledger, $deduper)->flush();

        self::assertCount(1, $ledger->appended);
    }

    public function test_the_buffer_is_emptied_even_when_the_write_fails(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $this->flusher($observer, $this->failingLedger())->flush();

        // Otherwise a failing ledger would replay the same exposures against every subsequent
        // request on this worker, and attribute them to the wrong day after midnight.
        self::assertSame([], $observer->drain());
    }

    public function test_a_failed_write_never_escapes(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $this->flusher($observer, $this->failingLedger())->flush();

        // The response has already been sent. There is nobody left to tell, and telemetry
        // must never become a second failure.
        $this->expectNotToPerformAssertions();
    }

    public function test_a_throwing_metrics_backend_cannot_escape_a_successful_write(): void
    {
        // The metrics adapter raises MetricNotDefinedException for a name the application has
        // not declared in its metrics config. Recording the success counter must therefore be
        // allowed to fail without taking the request's terminate phase with it.
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $flusher = new ExposureLedgerFlusher(
            $observer,
            $this->deduper(),
            $this->ledger(),
            $this->throwingMetrics(),
        );

        $flusher->flush();

        $this->expectNotToPerformAssertions();
    }

    public function test_a_throwing_metrics_backend_cannot_escape_a_failed_write(): void
    {
        // The dangerous one. This fires only on the error path, after the response is already
        // sent — so it is invisible in request logs and surfaces only once something else has
        // already gone wrong. A metrics call inside the catch block that exists to make
        // failures harmless must itself be unable to fail.
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $flusher = new ExposureLedgerFlusher(
            $observer,
            $this->deduper(),
            $this->failingLedger(),
            $this->throwingMetrics(),
        );

        $flusher->flush();

        $this->expectNotToPerformAssertions();
    }

    /**
     * Metrics whose backend throws on any counter, which is exactly how the real adapter
     * behaves for a name the application has not declared in its metrics config —
     * `MetricNotDefinedException` is raised by `counter()` itself, not by `increment()`.
     */
    private function throwingMetrics(): FlagEvaluationMetrics
    {
        return new FlagEvaluationMetrics(new class implements MetricsInterface {
            public function counter(string $name, array $labels = []): CounterInterface
            {
                throw new RuntimeException(sprintf('Metric "%s" is not defined.', $name));
            }

            public function gauge(string $name, array $labels = []): GaugeInterface
            {
                throw new RuntimeException(sprintf('Metric "%s" is not defined.', $name));
            }

            public function histogram(string $name, array $labels = []): HistogramInterface
            {
                throw new RuntimeException(sprintf('Metric "%s" is not defined.', $name));
            }
        });
    }

    public function test_terminate_flushes(): void
    {
        $observer = $this->observer();
        $observer->onExposure($this->record());

        $ledger = $this->ledger();
        $this->flusher($observer, $ledger)->terminate(
            new \Vortos\Http\Request(),
            new Response(),
        );

        self::assertCount(1, $ledger->appended);
    }

    private function flusher(
        LedgerExposureObserver $observer,
        ExposureLedgerInterface $ledger,
        ?DailyExposureDeduper $deduper = null,
    ): ExposureLedgerFlusher {
        return new ExposureLedgerFlusher(
            $observer,
            $deduper ?? $this->deduper(),
            $ledger,
            new FlagEvaluationMetrics(),
        );
    }

    private function observer(): LedgerExposureObserver
    {
        return new LedgerExposureObserver(
            new SubjectPseudonymiser(self::PEPPER),
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-09-11 12:00:00', new DateTimeZone('UTC'));
                }
            },
            enabled: true,
        );
    }

    private function deduper(): DailyExposureDeduper
    {
        return new DailyExposureDeduper(
            new class implements \Vortos\Cache\Contract\AtomicCacheInterface {
                /** @var array<string,true> */
                private array $keys = [];

                public function setNx(string $key, mixed $value, int $ttl): bool
                {
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
            },
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-09-11 12:00:00', new DateTimeZone('UTC'));
                }
            },
        );
    }

    /** @return ExposureLedgerInterface&object{appended: list<LedgerExposure>} */
    private function ledger(): ExposureLedgerInterface
    {
        return new class implements ExposureLedgerInterface {
            /** @var list<LedgerExposure> */
            public array $appended = [];

            public function append(array $exposures): int
            {
                foreach ($exposures as $exposure) {
                    $this->appended[] = $exposure;
                }

                return count($exposures);
            }
        };
    }

    private function failingLedger(): ExposureLedgerInterface
    {
        return new class implements ExposureLedgerInterface {
            public function append(array $exposures): int
            {
                throw new RuntimeException('the database is gone');
            }
        };
    }

    private function record(): ExposureRecord
    {
        return new ExposureRecord(
            flag:       'checkout',
            variant:    'true',
            contextKey: 'ctx-1',
            source:     ExposureSource::Server,
            timestamp:  null,
            groups:     ['organization' => 'org-1'],
            subjectId:  'user-42',
        );
    }
}
