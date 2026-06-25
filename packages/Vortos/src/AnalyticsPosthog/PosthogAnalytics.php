<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog;

use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Capability\AnalyticsCapability;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\Analytics\Transport\AnalyticsTransportInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Server-side PostHog driver: buffers mapped events locally (the decorator chain
 * above already buffers at the agnostic level — this small buffer exists so several
 * `capture()`/`identify()`/`group()` calls collapse into one `/batch` POST on
 * `flush()`), then POSTs through {@see AnalyticsTransportInterface}.
 *
 * Every transport call is wrapped in try/catch — satisfies the never-throws
 * contract even when the transport explodes (TCK-proven via a throwing transport).
 * Reads `POSTHOG_HOST`/`POSTHOG_PROJECT_API_KEY` from the environment at use-time —
 * a write-only ingestion key, never logged or stored on the instance.
 */
#[AsDriver('posthog')]
final class PosthogAnalytics implements AnalyticsInterface
{
    private const DEFAULT_HOST = 'https://us.i.posthog.com';

    /** @var list<array<string,mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly AnalyticsTransportInterface $transport,
        private readonly PosthogEventMapper $mapper,
        private readonly string $hostEnvVar = 'POSTHOG_HOST',
        private readonly string $apiKeyEnvVar = 'POSTHOG_PROJECT_API_KEY',
    ) {}

    public function name(): string
    {
        return 'posthog';
    }

    public function capture(AnalyticsEvent $event): void
    {
        try {
            $this->buffer[] = $this->mapper->mapEvent($event);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function identify(IdentitySet $identity): void
    {
        try {
            $this->buffer[] = $this->mapper->mapIdentity($identity);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function group(GroupAssociation $group): void
    {
        try {
            $this->buffer[] = $this->mapper->mapGroup($group);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];

        try {
            $apiKey = $this->env($this->apiKeyEnvVar);
            if ($apiKey === null) {
                return; // Not configured — drop silently; durability is the layer above's job.
            }

            $host = $this->env($this->hostEnvVar) ?? self::DEFAULT_HOST;
            $body = json_encode(['api_key' => $apiKey, 'batch' => $batch], JSON_THROW_ON_ERROR);

            $this->transport->send(rtrim($host, '/') . '/batch', $body, ['Content-Type' => 'application/json']);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            AnalyticsCapability::Batching->value => true,
            AnalyticsCapability::GroupAnalytics->value => true,
            AnalyticsCapability::ServerSide->value => true,
            AnalyticsCapability::OffHost->value => true,
            AnalyticsCapability::IdentityMerge->value => false,
        ]);
    }

    /** @internal test seam */
    public function bufferedCount(): int
    {
        return count($this->buffer);
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
