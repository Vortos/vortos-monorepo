<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Delivery;

/**
 * Notifies SSE streams that the flag config has changed (Block 16).
 * Implementations: Redis pub/sub (default when Redis is present), polling fallback.
 */
interface FlagChangeNotifierInterface
{
    /** Publish a change notification for the given environment. */
    public function notify(string $environment, string $version): void;

    /**
     * Block until a change is detected or timeout expires.
     * Returns the new version string, or null on timeout.
     */
    public function waitForChange(string $environment, string $lastVersion, float $timeoutSeconds = 30.0): ?string;
}
