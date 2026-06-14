<?php

declare(strict_types=1);

namespace Vortos\Logger\DependencyInjection;

use Monolog\Level;
use Vortos\Logger\Config\BufferPolicy;
use Vortos\Logger\Config\ChannelDefinition;
use Vortos\Logger\Config\LogChannel;
use Vortos\Logger\Config\ResolvedLoggingConfig;
use Vortos\Logger\Config\RotationPolicy;
use Vortos\Logger\Config\SinkDestination;
use Vortos\Logger\Exception\InvalidLoggingConfigException;
use Vortos\Observability\Config\ObservabilityModule;

/**
 * Fluent configuration object for vortos-logger.
 *
 * Loaded via require in LoggerExtension::load(). Every setting has a
 * sensible env-driven default — no config file is required.
 *
 * ## Pipeline model
 *
 *   Each LogChannel (app, http, cqrs, messaging, cache, security, audit,
 *   query, tooling — plus any custom channel you register) routes its
 *   records to one or more *sinks*. A sink is a destination (file, stream,
 *   syslog, or a custom handler) with its own level, buffer policy,
 *   rotation/retention, sampling, and optional hash-chaining.
 *
 *   By default every channel gets its own same-named sink:
 *     - dev:  file  var/log/{channel}.log, daily rotation, gzip, 14 files / 30 days / 1GB
 *     - prod: stream php://stderr, JSON
 *
 *   Security and Audit channels default to write-through (no buffering —
 *   zero loss on crash). Audit additionally hash-chains every record.
 *
 * ## Examples
 *
 *   // Route security + audit to a SIEM shipper in addition to local files
 *   $config->sink('siem')->customHandler('app.logging.siem_handler');
 *   $config->channel(LogChannel::Security)->alsoRouteTo('siem');
 *
 *   // Sample noisy cache logs at 1/100
 *   $config->sink(LogChannel::Cache->value)->sample(100);
 *
 *   // Silence a channel entirely
 *   $config->channel(LogChannel::Query)->disable();
 *
 *   // Send everything to stdout/stderr JSON only (no local files), even in dev
 *   foreach (LogChannel::cases() as $channel) {
 *       $config->sink($channel->value)->toStream('php://stderr');
 *   }
 */
final class VortosLoggingConfig
{
    private const AUDIT_RETENTION_FLOOR_DAYS = 365;

    private readonly string $env;

    /** @var array<string, SinkBuilder> */
    private array $sinkBuilders = [];

    /** @var array<string, ChannelBuilder> */
    private array $channelBuilders = [];

    private bool $introspectionEnabled;
    private bool $redactionEnabled = true;
    private bool $structuredEnabled = true;
    private bool $requestContextEnabled = true;
    private bool $correlationIdEnabled = true;
    private bool $failOnMissingIntegrations = true;

    private string $serviceName;
    private string $serviceVersion = '';
    private string $deploymentEnvironment;

    /** @var list<string> */
    private array $redactionKeys = [];

    private int $defaultFlushIntervalSeconds = 2;

    /** @var list<array{dsn: string, minLevel: Level}> */
    private array $sentryHandlers = [];

    /** @var list<array{webhook: string, minLevel: Level}> */
    private array $slackHandlers = [];

    /** @var list<array{to: string, minLevel: Level}> */
    private array $emailHandlers = [];

    public function __construct(string $env = '')
    {
        $this->env = $env ?: ($_ENV['APP_ENV'] ?? 'prod');
        $this->introspectionEnabled = $this->env === 'dev';
        $this->deploymentEnvironment = $this->env;
        $this->serviceName = $_ENV['OTEL_SERVICE_NAME'] ?? $_ENV['APP_NAME'] ?? 'app';
        $this->serviceVersion = $_ENV['APP_VERSION'] ?? '';
    }

    /**
     * Configure (or create) a sink by id. Sinks are referenced by id from
     * channel routing — `sink('audit')` configures the default sink for the
     * Audit channel, but you can also define arbitrary additional sink ids
     * (e.g. 'siem') and route channels to them.
     */
    public function sink(string $id): SinkBuilder
    {
        return $this->sinkBuilders[$id] ??= new SinkBuilder($id);
    }

    /**
     * Configure (or create) a channel's routing/level/enabled state.
     * Accepts a framework LogChannel or an arbitrary string for custom
     * channels registered by application code.
     */
    public function channel(LogChannel|string $name): ChannelBuilder
    {
        $key = $name instanceof LogChannel ? $name->value : $name;
        return $this->channelBuilders[$key] ??= new ChannelBuilder($key);
    }

    /**
     * Silence one or more framework channels entirely.
     * The App channel cannot be disabled.
     */
    public function disableChannel(LogChannel ...$channels): static
    {
        foreach ($channels as $channel) {
            if ($channel !== LogChannel::App) {
                $this->channel($channel)->disable();
            }
        }
        return $this;
    }

    /**
     * Disable framework logs by observability module — maps to the channel
     * that module logs to and disables it.
     */
    public function disableModule(ObservabilityModule ...$modules): static
    {
        foreach ($modules as $module) {
            $channel = $this->channelForModule($module);
            if ($channel !== null && $channel !== LogChannel::App) {
                $this->channel($channel)->disable();
            }
        }
        return $this;
    }

    /**
     * Default flush interval (seconds) for batched sinks that don't specify
     * their own via SinkBuilder::batched(). Default: 2.
     */
    public function flushInterval(int $seconds): static
    {
        $this->defaultFlushIntervalSeconds = max(1, $seconds);
        return $this;
    }

    /**
     * Include file/class/line information in log records.
     * Default: enabled only in dev.
     */
    public function introspection(bool $enabled = true): static
    {
        $this->introspectionEnabled = $enabled;
        return $this;
    }

    /**
     * Redact sensitive values from record context/extra (key-based) and
     * message/value content (pattern-based: emails, JWTs, card numbers, etc).
     * Default: enabled.
     *
     * @param list<string> $keys
     */
    public function redaction(bool $enabled = true, array $keys = []): static
    {
        $this->redactionEnabled = $enabled;
        $this->redactionKeys = array_values($keys);
        return $this;
    }

    /** Add ECS/OpenTelemetry-compatible fields to every record. Default: enabled. */
    public function structured(bool $enabled = true): static
    {
        $this->structuredEnabled = $enabled;
        return $this;
    }

    /** Add bounded HTTP/user/tenant context. Default: enabled. */
    public function requestContext(bool $enabled = true): static
    {
        $this->requestContextEnabled = $enabled;
        return $this;
    }

    /** Inject the active trace ID as 'trace_id'. Default: enabled. */
    public function correlationId(bool $enabled = true): static
    {
        $this->correlationIdEnabled = $enabled;
        return $this;
    }

    public function service(string $name, string $version = '', string $environment = ''): static
    {
        $this->serviceName = $name;
        $this->serviceVersion = $version;
        if ($environment !== '') {
            $this->deploymentEnvironment = $environment;
        }
        return $this;
    }

    /** When true, configured integrations (Sentry, etc.) must be installed or container build fails. */
    public function failOnMissingIntegrations(bool $enabled = true): static
    {
        $this->failOnMissingIntegrations = $enabled;
        return $this;
    }

    public function sentry(string $dsn, Level $minLevel = Level::Error): static
    {
        if ($dsn !== '') {
            $this->sentryHandlers[] = ['dsn' => $dsn, 'minLevel' => $minLevel];
        }
        return $this;
    }

    public function slack(string $webhook, Level $minLevel = Level::Critical): static
    {
        if ($webhook !== '') {
            $this->slackHandlers[] = ['webhook' => $webhook, 'minLevel' => $minLevel];
        }
        return $this;
    }

    public function email(string $to, Level $minLevel = Level::Error): static
    {
        if ($to !== '') {
            $this->emailHandlers[] = ['to' => $to, 'minLevel' => $minLevel];
        }
        return $this;
    }

    /**
     * Resolve the full pipeline: applies defaults for every framework
     * channel and any custom channels registered via channel(), then
     * validates the result. Throws InvalidLoggingConfigException on any
     * inconsistency.
     *
     * @internal Used by LoggerExtension.
     */
    public function resolve(): ResolvedLoggingConfig
    {
        $sinks = [];
        $channels = [];

        $allChannelNames = array_unique([
            ...array_map(static fn(LogChannel $c) => $c->value, LogChannel::cases()),
            ...array_keys($this->channelBuilders),
        ]);

        foreach ($allChannelNames as $name) {
            $logChannel = LogChannel::tryFrom($name);
            $channelBuilder = $this->channelBuilders[$name] ?? null;

            if ($channelBuilder?->isDisabled() === true) {
                $channels[$name] = new ChannelDefinition($name, [], Level::Debug, disabled: true);
                continue;
            }

            $sinkIds = $channelBuilder?->sinkIds($name) ?? [$name];

            // Only the channel's own default-named sink is auto-created;
            // any other sink id referenced via routeTo()/alsoRouteTo() must
            // be defined explicitly with $config->sink($id)->...
            if (!isset($this->sinkBuilders[$name]) && in_array($name, $sinkIds, true)) {
                $this->sinkBuilders[$name] = $this->defaultSinkBuilder($name, $logChannel);
            }

            $level = $channelBuilder?->getLevel() ?? $this->defaultLevel($logChannel);

            $channels[$name] = new ChannelDefinition($name, $sinkIds, $level);
        }

        // Materialize every referenced (or explicitly configured) sink builder.
        foreach ($this->sinkBuilders as $id => $builder) {
            $builder->applyDefaultDestinationIfUnset($this->env, $id);
            $sinks[$id] = $builder->build();
        }

        $this->validate($sinks, $channels);

        return new ResolvedLoggingConfig(
            env: $this->env,
            sinks: $sinks,
            channels: $channels,
            introspection: $this->introspectionEnabled,
            redaction: $this->redactionEnabled,
            redactionKeys: $this->redactionKeys,
            structured: $this->structuredEnabled,
            requestContext: $this->requestContextEnabled,
            correlationId: $this->correlationIdEnabled,
            serviceName: $this->serviceName,
            serviceVersion: $this->serviceVersion,
            deploymentEnvironment: $this->deploymentEnvironment,
            failOnMissingIntegrations: $this->failOnMissingIntegrations,
            sentryHandlers: $this->sentryHandlers,
            slackHandlers: $this->slackHandlers,
            emailHandlers: $this->emailHandlers,
            defaultFlushIntervalSeconds: $this->defaultFlushIntervalSeconds,
        );
    }

    private function defaultSinkBuilder(string $id, ?LogChannel $logChannel): SinkBuilder
    {
        $builder = new SinkBuilder($id);

        if ($this->env === 'dev') {
            $builder->toFile($id . '.log');
        } else {
            $builder->toStream('php://stderr');
        }

        if ($logChannel?->isWriteThroughByDefault() === true) {
            $builder->writeThrough();
        } else {
            $builder->batched($this->defaultFlushIntervalSeconds);
        }

        if ($logChannel === LogChannel::Audit) {
            $builder->hashChain(true);
            $builder->rotation(maxAgeDays: self::AUDIT_RETENTION_FLOOR_DAYS);
        }

        return $builder;
    }

    private function defaultLevel(?LogChannel $logChannel): Level
    {
        $isDev = $this->env === 'dev';

        if ($isDev) {
            return Level::Debug;
        }

        return $logChannel === LogChannel::App || $logChannel === null ? Level::Warning : Level::Error;
    }

    /**
     * @param array<string, \Vortos\Logger\Config\SinkDefinition> $sinks
     * @param array<string, ChannelDefinition> $channels
     */
    private function validate(array $sinks, array $channels): void
    {
        foreach ($channels as $channel) {
            if ($channel->disabled) {
                continue;
            }

            foreach ($channel->sinkIds as $sinkId) {
                if (!isset($sinks[$sinkId])) {
                    throw InvalidLoggingConfigException::unknownSink($channel->name, $sinkId);
                }
            }
        }

        foreach ($sinks as $sink) {
            if ($sink->destination === SinkDestination::Custom && $sink->customHandlerServiceId === null) {
                throw InvalidLoggingConfigException::customSinkMissingHandler($sink->id);
            }

            if ($sink->destination === SinkDestination::File && ($sink->path === null || $sink->path === '')) {
                throw InvalidLoggingConfigException::fileSinkMissingPath($sink->id);
            }
        }

        $auditChannel = $channels[LogChannel::Audit->value] ?? null;
        if ($auditChannel !== null && !$auditChannel->disabled) {
            foreach ($auditChannel->sinkIds as $sinkId) {
                $sink = $sinks[$sinkId];
                $builder = $this->sinkBuilders[$sinkId] ?? null;

                if (
                    $sink->destination === SinkDestination::File
                    && $sink->rotation->enabled
                    && $sink->rotation->maxAgeDays < self::AUDIT_RETENTION_FLOOR_DAYS
                    && $builder?->complianceRiskAcknowledged() !== true
                ) {
                    throw InvalidLoggingConfigException::auditRetentionBelowFloor(
                        $sink->rotation->maxAgeDays,
                        self::AUDIT_RETENTION_FLOOR_DAYS,
                    );
                }
            }
        }
    }

    private function channelForModule(ObservabilityModule $module): ?LogChannel
    {
        return match ($module) {
            ObservabilityModule::Http => LogChannel::Http,
            ObservabilityModule::Cqrs => LogChannel::Cqrs,
            ObservabilityModule::Messaging => LogChannel::Messaging,
            ObservabilityModule::Cache => LogChannel::Cache,
            ObservabilityModule::Security,
            ObservabilityModule::Auth,
            ObservabilityModule::Authorization,
            ObservabilityModule::FeatureFlags => LogChannel::Security,
            ObservabilityModule::Persistence,
            ObservabilityModule::PersistenceDbal,
            ObservabilityModule::PersistenceMongo,
            ObservabilityModule::PersistenceOrm => LogChannel::Query,
            ObservabilityModule::Config,
            ObservabilityModule::Debug,
            ObservabilityModule::Docker,
            ObservabilityModule::Make,
            ObservabilityModule::Mcp,
            ObservabilityModule::Migration,
            ObservabilityModule::Observability,
            ObservabilityModule::Setup => LogChannel::Tooling,
            default => null,
        };
    }
}
