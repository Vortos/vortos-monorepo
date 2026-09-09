<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\Exposure\ActiveFlagCollector;
use Vortos\FeatureFlags\Exposure\ExposureObserverInterface;
use Vortos\FeatureFlags\Exposure\ExposureRecord;
use Vortos\FeatureFlags\Exposure\ExposureReportingFlagRegistry;
use Vortos\FeatureFlags\Exposure\ExposureSource;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;

/**
 * The half of the exposure pipeline that never existed.
 *
 * Every server-side gate — `#[RequiresFlag]`, the route middleware, a direct `isEnabled()` —
 * used to record a Prometheus counter and tell the analytics backend nothing, so a flag that
 * only gates backend behaviour looked like it had no traffic at all.
 */
final class ExposureReportingFlagRegistryTest extends TestCase
{
    public function test_is_enabled_reports_an_exposure(): void
    {
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);

        $this->assertTrue($registry->isEnabled('checkout', new FlagContext('u1')));

        $this->assertCount(1, $observer->records);
        $this->assertSame('checkout', $observer->records[0]->flag);
        $this->assertSame(ExposureRecord::BOOL_TRUE, $observer->records[0]->variant);
        $this->assertSame(ExposureSource::Server, $observer->records[0]->source);
    }

    public function test_an_off_flag_is_still_an_exposure(): void
    {
        // "The user was shown the control" is data, not silence. Reporting only the on-path
        // would make every experiment look like it had no control group.
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => false]), $observer);

        $this->assertFalse($registry->isEnabled('checkout', new FlagContext('u1')));
        $this->assertSame(ExposureRecord::BOOL_FALSE, $observer->records[0]->variant);
    }

    public function test_variant_reports_the_variant_key(): void
    {
        $observer = $this->observer();
        $registry = $this->registry($this->inner([], ['checkout' => 'blue']), $observer);

        $this->assertSame('blue', $registry->variant('checkout', new FlagContext('u1')));
        $this->assertSame('blue', $observer->records[0]->variant);
    }

    public function test_bulk_bootstrap_is_not_an_exposure(): void
    {
        // allForContext() is the SDK's cache fill. Counting it would report every flag in the
        // project on every page load and destroy the numbers.
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['a' => true]), $observer);

        $registry->allForContext(new FlagContext('u1'));

        $this->assertSame([], $observer->records);
    }

    public function test_repeated_reads_in_one_request_report_once(): void
    {
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);
        $context = new FlagContext('u1');

        for ($i = 0; $i < 10; $i++) {
            $registry->isEnabled('checkout', $context);
        }

        $this->assertCount(1, $observer->records);
    }

    public function test_a_different_subject_is_a_different_exposure(): void
    {
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);

        $registry->isEnabled('checkout', new FlagContext('u1'));
        $registry->isEnabled('checkout', new FlagContext('u2'));

        $this->assertCount(2, $observer->records);
    }

    public function test_a_changed_result_reports_again(): void
    {
        $observer = $this->observer();
        $inner = new class implements FlagRegistryInterface {
            public bool $next = false;
            public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool { return $this->next; }
            public function variant(string $name, FlagContext $context = new FlagContext()): string { return 'control'; }
            public function allForContext(FlagContext $context = new FlagContext()): array { return []; }
        };
        $registry = $this->registry($inner, $observer);
        $context = new FlagContext('u1');

        $registry->isEnabled('checkout', $context);
        $inner->next = true;
        $registry->isEnabled('checkout', $context);

        $this->assertCount(2, $observer->records, 'a flag that flipped mid-request is a new exposure');
    }

    public function test_group_associations_are_attached(): void
    {
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);

        $registry->isEnabled('checkout', new FlagContext('u1', [], ['tenantId' => 'org-7']));

        $this->assertSame(['organization' => 'org-7'], $observer->records[0]->groups);
    }

    public function test_the_collector_sees_every_read_even_when_deduped(): void
    {
        // Dedupe governs *reporting*; attribution must still describe the whole request.
        $collector = new ActiveFlagCollector();
        $registry = $this->registry($this->inner(['a' => true, 'b' => false]), $this->observer(), $collector);
        $context = new FlagContext('u1');

        $registry->isEnabled('a', $context);
        $registry->isEnabled('a', $context);
        $registry->isEnabled('b', $context);

        $this->assertSame(['a' => 'true', 'b' => 'false'], $collector->all());
    }

    public function test_a_throwing_observer_never_breaks_evaluation(): void
    {
        $inner = $this->inner(['checkout' => true]);
        $throwing = new class implements ExposureObserverInterface {
            public function onExposure(ExposureRecord $record): void { throw new RuntimeException('boom'); }
        };

        $registry = new ExposureReportingFlagRegistry($inner, new ActiveFlagCollector(), [$throwing]);

        $this->assertTrue($registry->isEnabled('checkout', new FlagContext('u1')), 'the flag value must survive a broken observer');
    }

    public function test_results_are_returned_unchanged(): void
    {
        $inner = $this->inner(['on' => true, 'off' => false], ['v' => 'blue']);
        $registry = $this->registry($inner, $this->observer());
        $context = new FlagContext('u1');

        $this->assertSame($inner->isEnabled('on', $context), $registry->isEnabled('on', $context));
        $this->assertSame($inner->isEnabled('off', $context), $registry->isEnabled('off', $context));
        $this->assertSame($inner->variant('v', $context), $registry->variant('v', $context));
    }

    public function test_disabled_reports_nothing_but_still_evaluates(): void
    {
        $observer = $this->observer();
        $registry = new ExposureReportingFlagRegistry(
            $this->inner(['checkout' => true]),
            new ActiveFlagCollector(),
            [$observer],
            enabled: false,
        );

        $this->assertTrue($registry->isEnabled('checkout', new FlagContext('u1')));
        $this->assertSame([], $observer->records);
    }

    public function test_reporting_is_bounded_per_request(): void
    {
        $observer = $this->observer();
        $registry = new ExposureReportingFlagRegistry(
            $this->inner([]),
            new ActiveFlagCollector(),
            [$observer],
            maxPerRequest: 3,
        );
        $context = new FlagContext('u1');

        for ($i = 0; $i < 10; $i++) {
            $registry->isEnabled("flag-{$i}", $context);
        }

        $this->assertCount(3, $observer->records);
    }

    public function test_reset_clears_the_dedupe_set_between_requests(): void
    {
        // Without this, a long-lived worker reports a flag once and then never again for the
        // rest of the process's life — the numbers simply stop.
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);
        $context = new FlagContext('u1');

        $this->assertInstanceOf(ResetInterface::class, $registry);

        $registry->isEnabled('checkout', $context);
        $registry->reset();
        $registry->isEnabled('checkout', $context);

        $this->assertCount(2, $observer->records);
    }

    public function test_the_reported_identity_is_the_subject_not_the_context_fingerprint(): void
    {
        // Two silent failures ride on this. A consent decision is looked up by the reported id,
        // so a fingerprint matches no consent record and every exposure is dropped; and an
        // exposure recorded against a fingerprint is a different person from the one who later
        // converts, so nothing can ever be attributed to a variant.
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);

        $registry->isEnabled('checkout', new FlagContext('user-123', [], ['tenantId' => 'org-7']));

        $record = $observer->records[0];
        $this->assertSame('user-123', $record->subjectId);
        $this->assertSame('user-123', $record->analyticsId());
        $this->assertNotSame($record->contextKey, $record->analyticsId());
    }

    public function test_an_anonymous_subject_falls_back_to_the_fingerprint(): void
    {
        $observer = $this->observer();
        $registry = $this->registry($this->inner(['checkout' => true]), $observer);

        $registry->isEnabled('checkout', new FlagContext());

        $record = $observer->records[0];
        $this->assertNull($record->subjectId);
        $this->assertSame($record->contextKey, $record->analyticsId(), 'anonymous exposures must still be distinct from one another');
    }

    /** @param array<string,bool> $bools @param array<string,string> $variants */
    private function inner(array $bools, array $variants = []): FlagRegistryInterface
    {
        return new class($bools, $variants) implements FlagRegistryInterface {
            /** @param array<string,bool> $bools @param array<string,string> $variants */
            public function __construct(private array $bools, private array $variants) {}

            public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
            {
                return $this->bools[$name] ?? false;
            }

            public function variant(string $name, FlagContext $context = new FlagContext()): string
            {
                return $this->variants[$name] ?? 'control';
            }

            public function allForContext(FlagContext $context = new FlagContext()): array
            {
                return $this->bools;
            }
        };
    }

    private function observer(): object
    {
        return new class implements ExposureObserverInterface {
            /** @var list<ExposureRecord> */
            public array $records = [];

            public function onExposure(ExposureRecord $record): void
            {
                $this->records[] = $record;
            }
        };
    }

    private function registry(
        FlagRegistryInterface $inner,
        object $observer,
        ?ActiveFlagCollector $collector = null,
    ): ExposureReportingFlagRegistry {
        return new ExposureReportingFlagRegistry($inner, $collector ?? new ActiveFlagCollector(), [$observer]);
    }
}
