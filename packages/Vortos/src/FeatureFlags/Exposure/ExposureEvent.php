<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

/**
 * One exposure reported by a client SDK (wire contract §5): the SDK observed a user being
 * exposed to `name` (and assigned `variant`, if multivariate) at `timestamp`.
 */
final class ExposureEvent
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $variant,
        public readonly ?int $timestamp,
    ) {}

    /**
     * Build from a decoded JSON item, or null if it is malformed (missing/blank name,
     * wrong types). Returning null lets the ingest path skip junk without failing the
     * whole batch.
     *
     * @param array<string,mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $name = $item['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $variant = $item['variant'] ?? null;
        if ($variant !== null && !is_string($variant)) {
            $variant = null;
        }

        $timestamp = $item['timestamp'] ?? null;
        $timestamp = is_int($timestamp) ? $timestamp : (is_numeric($timestamp) ? (int) $timestamp : null);

        return new self($name, $variant, $timestamp);
    }
}
