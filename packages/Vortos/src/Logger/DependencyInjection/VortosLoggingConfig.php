<?php

declare(strict_types=1);

namespace Vortos\Logger\DependencyInjection;

use Monolog\Level;
use Vortos\Logger\Config\LogChannel;

/**
 * Fluent configuration object for vortos-logger.
 *
 * Loaded via require in LoggerExtension::load().
 * Every setting has a sensible env-driven default — no config file is required.
 *
 * ## Standard usage
 *
 * Create config/logging.php in your project:
 *
 *   return static function (VortosLoggingConfig $config): void {
 *       $config
 *           ->disableChannel(LogChannel::Cache, LogChannel::Query)
 *           ->rotation(true, maxFiles: 14)
 *           ->sentry(dsn: $_ENV['SENTRY_DSN'] ?? '');
 *   };
 *
 * ## Channels
 *
 *   App       — userland default, aliased to LoggerInterface
 *   Http      — request/response lifecycle, routing
 *   Cqrs      — CommandBus dispatch, handler execution
 *   Messaging — EventBus, Kafka, Outbox, DeadLetter
 *   Cache     — Redis get/set/delete (high volume — consider disabling in prod)
 *   Security  — auth failures, token validation, authz denials
 *   Query     — slow DB queries, DBAL/Mongo operations
 *
 * ## Alerting handlers
 *
 *   Sentry, Slack, and email handlers are only registered when explicitly configured.
 *   Each requires the corresponding library in your project's composer.json.
 *   They are always added at or above the configured minimum level.
 */
final class VortosLoggingConfig
{
    /** @var array<string, Level> channel name → minimum level */
    private array $channelLevels = [];

    /** @var list<string> disabled channel names */
    private array $disabledChannels = [];

    private bool $rotationEnabled;
    private int $maxFiles = 30;
    private bool $bufferEnabled = true;
    private bool $correlationIdEnabled = true;

    /** @var list<array{dsn: string, minLevel: Level}> */
    private array $sentryHandlers = [];

    /** @var list<array{webhook: string, minLevel: Level}> */
    private array $slackHandlers = [];

    /** @var list<array{to: string, minLevel: Level}> */
    private array $emailHandlers = [];

    public function __construct(string $env = '')
    {
        $this->rotationEnabled = ($env ?: ($_ENV['APP_ENV'] ?? 'prod')) === 'dev';
    }

    /**
     * Override the minimum log level for a specific channel.
     *
     * By default all channels use environment-driven levels:
     *   dev  → DEBUG
     *   prod → ERROR (framework channels), WARNING (app channel)
     */
    public function channel(LogChannel $channel, Level $minLevel): static
    {
        $this->channelLevels[$channel->value] = $minLevel;
        return $this;
    }

    /**
     * Silence one or more framework channels entirely.
     *
     * Useful for high-volume channels (Cache, Query) in production.
     * The App channel cannot be disabled.
     */
    public function disableChannel(LogChannel ...$channels): static
    {
        foreach ($channels as $channel) {
            if ($channel !== LogChannel::App) {
                $this->disabledChannels[] = $channel->value;
            }
        }
        return $this;
    }

    /**
     * Enable rotating log files instead of appending to a single file.
     *
     * Only applies to the file handler (dev environment).
     * Rotation creates date-stamped files and deletes the oldest when $maxFiles is exceeded.
     * Default: enabled in dev, disabled in prod (prod logs to stderr).
     */
    public function rotation(bool $enabled, int $maxFiles = 30): static
    {
        $this->rotationEnabled = $enabled;
        $this->maxFiles = $maxFiles;
        return $this;
    }

    /**
     * Wrap the real handler in a BufferHandler that flushes once at request end.
     *
     * Reduces disk I/O in FrankenPHP worker mode from N writes per request to 1.
     * Default: enabled.
     */
    public function buffer(bool $enabled = true): static
    {
        $this->bufferEnabled = $enabled;
        return $this;
    }

    /**
     * Inject the active trace ID into every log record as the 'trace_id' extra field.
     *
     * Requires vortos/vortos-tracing. When tracing is disabled (NoOpTracer),
     * the processor silently no-ops — no error is thrown.
     * Default: enabled.
     */
    public function correlationId(bool $enabled = true): static
    {
        $this->correlationIdEnabled = $enabled;
        return $this;
    }

    /**
     * Route log records at or above $minLevel to Sentry.
     *
     * Requires sentry/sentry in your project's composer.json.
     * Multiple calls register multiple Sentry integrations.
     */
    public function sentry(string $dsn, Level $minLevel = Level::Error): static
    {
        if ($dsn !== '') {
            $this->sentryHandlers[] = ['dsn' => $dsn, 'minLevel' => $minLevel];
        }
        return $this;
    }

    /**
     * Route log records at or above $minLevel to a Slack webhook.
     *
     * Uses Monolog's SlackWebhookHandler — no extra library required.
     */
    public function slack(string $webhook, Level $minLevel = Level::Critical): static
    {
        if ($webhook !== '') {
            $this->slackHandlers[] = ['webhook' => $webhook, 'minLevel' => $minLevel];
        }
        return $this;
    }

    /**
     * Route log records at or above $minLevel to an email address.
     *
     * Uses Monolog's NativeMailerHandler — no extra library required.
     */
    public function email(string $to, Level $minLevel = Level::Error): static
    {
        if ($to !== '') {
            $this->emailHandlers[] = ['to' => $to, 'minLevel' => $minLevel];
        }
        return $this;
    }

    /** @internal Used by LoggerExtension */
    public function toArray(): array
    {
        return [
            'channel_levels'       => $this->channelLevels,
            'disabled_channels'    => $this->disabledChannels,
            'rotation_enabled'     => $this->rotationEnabled,
            'max_files'            => $this->maxFiles,
            'buffer_enabled'       => $this->bufferEnabled,
            'correlation_id'       => $this->correlationIdEnabled,
            'sentry_handlers'      => $this->sentryHandlers,
            'slack_handlers'       => $this->slackHandlers,
            'email_handlers'       => $this->emailHandlers,
        ];
    }
}
