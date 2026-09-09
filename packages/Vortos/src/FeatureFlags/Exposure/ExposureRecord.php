<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

/**
 * One accepted exposure, as handed to an {@see ExposureObserverInterface}.
 *
 * Replaces the earlier positional `(flag, variant, contextKey)` signature, which threw
 * away two things the analytics backends need and could not reconstruct:
 *
 *  - **`timestamp`** — the SDK reports *when* it observed the exposure. Dropping it
 *    stamped every exposure at ingest time, which quietly skews any time-series or
 *    experiment window built on top of it.
 *  - **`groups`** — the org/account the subject belongs to. Without it an exposure
 *    cannot be broken down per tenant, which is the single most-asked question of a
 *    B2B rollout ("who has this on?").
 *
 * `variant` is the evaluated result rendered as a string: a multivariate flag reports
 * its variant key, and a boolean flag reports `'true'`/`'false'` (see
 * {@see self::BOOL_TRUE}). Rendering it as a string here keeps this VO
 * provider-agnostic — a backend that wants a real JSON boolean coerces at its own
 * mapping edge, which is where provider naming belongs.
 */
final readonly class ExposureRecord
{
    public const BOOL_TRUE  = 'true';
    public const BOOL_FALSE = 'false';

    /** @param array<string,string> $groups group type => group key (e.g. `organization` => org id) */
    public function __construct(
        public string $flag,
        public ?string $variant,
        public string $contextKey,
        public ExposureSource $source = ExposureSource::Sdk,
        public ?int $timestamp = null,
        public array $groups = [],
    ) {}

    /** Render a boolean evaluation as the variant string this VO carries. */
    public static function boolVariant(bool $result): string
    {
        return $result ? self::BOOL_TRUE : self::BOOL_FALSE;
    }

    /** The dedupe identity of an exposure: the same subject seeing the same result again is not news. */
    public function dedupeKey(): string
    {
        return $this->contextKey . '|' . $this->flag . '|' . ($this->variant ?? '');
    }
}
