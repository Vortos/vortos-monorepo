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
 * ## `subjectId` versus `contextKey`
 *
 * These are two different things and conflating them breaks everything downstream, silently.
 *
 * `contextKey` is a *fingerprint of the whole evaluation context* — the subject plus every
 * targeting attribute. It is a dedupe key, nothing else, and it changes whenever any attribute
 * changes (a different device, a different country) even for the same person.
 *
 * `subjectId` is the stable analytics identity — the same id the rest of the application
 * reports events under. It is what an analytics backend must receive as the distinct id, for
 * two reasons that both fail without a single error being raised: a consent decision is looked
 * up by that id, so a fingerprint resolves to no consent record and every exposure is dropped;
 * and an exposure recorded against a fingerprint is a *different person* from the one who
 * later converts, so no metric can ever be attributed to a variant.
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
        public ?string $subjectId = null,
    ) {}

    /**
     * The identity this exposure should be reported under: the stable subject when we have one,
     * and only then the context fingerprint, which at least keeps anonymous exposures distinct
     * from one another.
     */
    public function analyticsId(): string
    {
        return $this->subjectId !== null && $this->subjectId !== '' ? $this->subjectId : $this->contextKey;
    }

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
