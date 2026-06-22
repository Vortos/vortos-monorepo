<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Delivery;

use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;

/**
 * Polling-based fallback notifier: compares the config version at an interval
 * (Block 16). Used when Redis pub/sub is unavailable.
 */
final class PollingFlagChangeNotifier implements FlagChangeNotifierInterface
{
    /** @var array<string,string> env → last notified version */
    private array $versions = [];

    public function __construct(
        private readonly FlagRegistryInterface $registry,
        private readonly float $pollIntervalSeconds = 2.0,
    ) {}

    public function notify(string $environment, string $version): void
    {
        $this->versions[$environment] = $version;
    }

    public function waitForChange(string $environment, string $lastVersion, float $timeoutSeconds = 30.0): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $result  = $this->registry->allForContext(new FlagContext());
            $current = $result['version'] ?? '';

            if ($current !== '' && $current !== $lastVersion) {
                return $current;
            }

            $sleepUs = (int) ($this->pollIntervalSeconds * 1_000_000);
            $remain  = (int) (($deadline - microtime(true)) * 1_000_000);

            if ($remain <= 0) {
                break;
            }

            usleep(min($sleepUs, $remain));
        }

        return null;
    }
}
