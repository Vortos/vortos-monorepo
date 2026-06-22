<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Delivery;

/**
 * Redis pub/sub-based notifier (Block 16). Publishes a version string to a
 * per-environment channel; SSE endpoint subscribes and pushes to clients.
 */
final class RedisFlagChangeNotifier implements FlagChangeNotifierInterface
{
    private const CHANNEL_PREFIX = 'vortos:flags:change:';

    public function __construct(
        private readonly \Redis $publisher,
        private readonly \Redis $subscriber,
    ) {}

    public function notify(string $environment, string $version): void
    {
        $this->publisher->publish(self::CHANNEL_PREFIX . $environment, $version);
    }

    public function waitForChange(string $environment, string $lastVersion, float $timeoutSeconds = 30.0): ?string
    {
        $channel = self::CHANNEL_PREFIX . $environment;
        $result  = null;

        $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, $timeoutSeconds);

        try {
            $this->subscriber->subscribe([$channel], function (\Redis $redis, string $chan, string $message) use (&$result, $lastVersion) {
                if ($message !== $lastVersion) {
                    $result = $message;
                    $redis->unsubscribe([$chan]);
                }
            });
        } catch (\RedisException) {
            // Timeout or connection lost — return null
        }

        return $result;
    }
}
