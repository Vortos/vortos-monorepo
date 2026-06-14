<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

use Monolog\Level;

/**
 * Fully-resolved, validated logging pipeline — output of VortosLoggingConfig::resolve().
 *
 * Immutable. LoggerExtension wires the container purely from this object.
 */
final class ResolvedLoggingConfig
{
    /**
     * @param array<string, SinkDefinition>   $sinks
     * @param array<string, ChannelDefinition> $channels
     * @param list<string>                     $redactionKeys
     * @param list<array{dsn: string, minLevel: Level}>     $sentryHandlers
     * @param list<array{webhook: string, minLevel: Level}> $slackHandlers
     * @param list<array{to: string, minLevel: Level}>      $emailHandlers
     */
    public function __construct(
        public readonly string $env,
        public readonly array $sinks,
        public readonly array $channels,
        public readonly bool $introspection,
        public readonly bool $redaction,
        public readonly array $redactionKeys,
        public readonly bool $structured,
        public readonly bool $requestContext,
        public readonly bool $correlationId,
        public readonly string $serviceName,
        public readonly string $serviceVersion,
        public readonly string $deploymentEnvironment,
        public readonly bool $failOnMissingIntegrations,
        public readonly array $sentryHandlers,
        public readonly array $slackHandlers,
        public readonly array $emailHandlers,
        public readonly int $defaultFlushIntervalSeconds,
    ) {}
}
