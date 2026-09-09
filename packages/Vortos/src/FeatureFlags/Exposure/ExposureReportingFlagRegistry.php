<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;

/**
 * Emits an exposure for every *server-side* flag evaluation, closing the half of the
 * exposure pipeline that never existed.
 *
 * Before this decorator, only a client SDK could produce an exposure (by POSTing to the
 * public exposure endpoint). Every server-side gate — `#[RequiresFlag]`, the route
 * middleware, a direct `isEnabled()` in a handler — recorded a Prometheus counter and
 * nothing else. The consequence was silent and severe: a flag that only ever gates
 * backend behaviour appeared to have *zero* traffic in the analytics backend, so it
 * could not be measured, charted, or used as an experiment exposure at all.
 *
 * ## What counts as an exposure
 *
 * `isEnabled()` and `variant()` do — the caller is about to *act* on the value, which is
 * the definition of an exposure. `allForContext()` deliberately does not: it is the bulk
 * bootstrap the client SDK calls to fill its cache, and treating a cache fill as an
 * exposure would report every flag in the project on every page load and destroy the
 * numbers. The SDK reports those individually when it actually reads them.
 *
 * ## Guarantees
 *
 *  - **Never changes a result.** The inner registry's value is returned untouched on
 *    every path, including when reporting throws.
 *  - **Never throws into evaluation.** Reporting is wrapped whole; a broken observer or
 *    a broken group resolver cannot turn a flag lookup into a 500.
 *  - **Deduped per request** by `(context, flag, variant)`, so a flag read in a loop
 *    reports one exposure, not thousands.
 *  - **Bounded and resettable.** The dedupe set is capped and cleared through
 *    {@see ResetInterface} after `kernel.terminate`, so a long-lived worker cannot carry
 *    one request's exposures into the next.
 *
 * Flag *names* on this path come from application code (attributes, enum cases, static
 * route maps), never from request input, so — unlike the SDK ingest path — there is no
 * unknown-flag cardinality guard here. That mirrors what the metrics decorator already
 * assumes about the same names.
 */
final class ExposureReportingFlagRegistry implements FlagRegistryInterface, ResetInterface
{
    public const DEFAULT_MAX_PER_REQUEST = 500;

    /** @var array<string,true> */
    private array $seen = [];

    /** @param iterable<ExposureObserverInterface> $observers */
    public function __construct(
        private readonly FlagRegistryInterface $inner,
        private readonly ActiveFlagCollector $collector,
        private readonly iterable $observers = [],
        private readonly ExposureGroupResolver $groupResolver = new ExposureGroupResolver(),
        private readonly bool $enabled = true,
        private readonly int $maxPerRequest = self::DEFAULT_MAX_PER_REQUEST,
    ) {}

    public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
    {
        $result = $this->inner->isEnabled($name, $context);

        $this->report($name, ExposureRecord::boolVariant($result), $context);

        return $result;
    }

    public function variant(string $name, FlagContext $context = new FlagContext()): string
    {
        $variant = $this->inner->variant($name, $context);

        $this->report($name, $variant, $context);

        return $variant;
    }

    /**
     * Bulk bootstrap — passed straight through. See the class docblock: a cache fill is
     * not an exposure.
     *
     * @return array{
     *     flags: list<string>,
     *     variants: array<string,string>,
     *     payloads: array<string,mixed>,
     *     version: string
     * }
     */
    public function allForContext(FlagContext $context = new FlagContext()): array
    {
        return $this->inner->allForContext($context);
    }

    public function reset(): void
    {
        $this->seen = [];
    }

    /**
     * Best-effort exposure reporting. Wrapped whole: evaluation already returned, and
     * nothing here may be allowed to change or break that.
     */
    private function report(string $name, ?string $variant, FlagContext $context): void
    {
        if (!$this->enabled || $name === '') {
            return;
        }

        try {
            $record = new ExposureRecord(
                flag:       $name,
                variant:    $variant,
                contextKey: $context->cacheKey(),
                source:     ExposureSource::Server,
                timestamp:  time(),
                groups:     $this->groupResolver->resolve($context),
                subjectId:  $context->userId,
            );

            // Recorded before the dedupe check: the collector describes the *current*
            // request's flag state for event attribution, so a flag read twice must still
            // be attributed even though only the first read reports an exposure.
            $this->collector->record($name, $variant);

            $dedupeKey = $record->dedupeKey();
            if (isset($this->seen[$dedupeKey])) {
                return;
            }

            if (count($this->seen) >= $this->maxPerRequest) {
                return;
            }

            $this->seen[$dedupeKey] = true;

            foreach ($this->observers as $observer) {
                try {
                    $observer->onExposure($record);
                } catch (Throwable) {
                    // Intentionally swallowed: an observer can never break evaluation.
                }
            }
        } catch (Throwable) {
            // Intentionally swallowed: reporting is never worth a failed request.
        }
    }
}
