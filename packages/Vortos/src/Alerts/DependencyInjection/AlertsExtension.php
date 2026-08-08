<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Vortos\Alerts\Integration\AlertSourceInterface;
use Vortos\Alerts\Integration\Messaging\QueueBacklogAlertSource;
use Vortos\Alerts\Integration\Messaging\QueueBacklogProviderInterface;
use Vortos\Alerts\Runtime\AlertSourceTicker;
use Vortos\Alerts\Runtime\StaleAlertResolver;
use Vortos\Alerts\Schedule\EvaluateAlertSourcesCommand;
use Vortos\Alerts\Schedule\EvaluateAlertSourcesHandler;
use Vortos\Alerts\Schedule\EvaluateAlertSourcesSchedule;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Alerts\AlertDispatcher;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Console\AckCommand;
use Vortos\Alerts\Console\DrainCommand;
use Vortos\Alerts\Console\RotationShowCommand;
use Vortos\Alerts\Console\SilenceCommand;
use Vortos\Alerts\Console\SupervisorEventListenerCommand;
use Vortos\Alerts\Console\TestAlertCommand;
use Vortos\Alerts\Console\ValidateRulesCommand;
use Vortos\Alerts\Dedupe\AlertStateStoreInterface;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DbalAlertStateStore;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\ReminderBackoff;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\Escalation\AckStoreInterface;
use Vortos\Alerts\Escalation\AckTokenSigner;
use Vortos\Alerts\Escalation\DbalAckStore;
use Vortos\Alerts\Escalation\DbalMaintenanceSilenceStore;
use Vortos\Alerts\Escalation\EscalationEngine;
use Vortos\Alerts\Escalation\EscalationPolicy;
use Vortos\Alerts\Escalation\EscalationTier;
use Vortos\Alerts\Escalation\InMemoryAckStore;
use Vortos\Alerts\Escalation\InMemoryMaintenanceSilenceStore;
use Vortos\Alerts\Escalation\MaintenanceSilenceStoreInterface;
use Vortos\Alerts\Escalation\OnCallRotation;
use Vortos\Alerts\Escalation\QuietHoursPolicy;
use Vortos\Alerts\Escalation\Responder;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorder;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Alerts\Integration\Audit\AlertAuditViewRepositoryInterface;
use Vortos\Alerts\Integration\Audit\DbalAlertAuditViewRepository;
use Vortos\Alerts\Integration\Slo\NullSloBurnRateProvider;
use Vortos\Alerts\Integration\Slo\SloBurnAlertSource;
use Vortos\Alerts\Integration\Slo\SloBurnRateProviderInterface;
use Vortos\Alerts\RateLimit\OutboundRateLimiterInterface;
use Vortos\Alerts\RateLimit\OutboundRateLimitConfig;
use Vortos\Alerts\RateLimit\SlidingWindowOutboundRateLimiter;
use Vortos\Alerts\Notifier\Driver\GuzzleNotifierTransport;
use Vortos\Alerts\Notifier\Driver\HttpNotifierTransportInterface;
use Vortos\Alerts\Notifier\Driver\Null\NullNotifier;
use Vortos\Alerts\Notifier\Driver\Slack\SlackNotifier;
use Vortos\Alerts\Notifier\Driver\Telegram\TelegramNotifier;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfGuard;
use Vortos\Alerts\Notifier\Driver\Webhook\WebhookNotifier;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierRegistry;
use Vortos\Alerts\Notifier\OutboxNotifier;
use Vortos\Alerts\Preflight\AlertAuditLedgerCheck;
use Vortos\Alerts\Preflight\AlertRulesDoctorCheck;
use Vortos\Alerts\Routing\ChannelDefinition;
use Vortos\Alerts\Routing\ChannelRegistry;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Routing\Router;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleValidator;
use Vortos\Alerts\DependencyInjection\Compiler\CollectNotifiersPass;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Slo\SloRegistry;

final class AlertsExtension extends Extension
{
    /**
     * Tag for {@see \Vortos\Alerts\Integration\AlertSourceInterface} implementations. Tagged
     * sources are handed to AlertSourceTicker, which the framework schedules — registering a source
     * without this tag leaves it inert.
     */
    public const SOURCE_TAG = 'vortos.alerts.source';

    /** Tag for {@see QueueBacklogProviderInterface} implementations contributed by other packages. */
    public const BACKLOG_PROVIDER_TAG = 'vortos.alerts.queue_backlog_provider';

    public function getAlias(): string
    {
        return 'vortos_alerts';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->registerEnvDefaults($container);
        $this->registerNotifierSeam($container);
        $this->registerDefaultDrivers($container);
        $this->registerRules($container);
        $this->registerDedupe($container);
        $this->registerRouting($container);
        $this->registerEscalation($container);
        $this->registerDispatcher($container);
        $this->registerSlo($container);
        $this->registerBackupIntegration($container);
        $this->registerDeployIntegration($container);
        $this->registerQueueBacklogIntegration($container);
        $this->registerSourceTicker($container);
        $this->registerCommands($container);

        $container->registerForAutoconfiguration(NotifierInterface::class)
            ->addTag(CollectNotifiersPass::TAG);

        // Any source an app or another package registers is ticked automatically.
        $container->registerForAutoconfiguration(AlertSourceInterface::class)
            ->addTag(self::SOURCE_TAG);
    }

    /**
     * Defaults for every env var this extension references as `%env(...)%`.
     *
     * These used to be `?? <default>` inside inline `$_ENV[...]` reads. An inline read resolves
     * whenever the container is COMPILED, which is not necessarily on the host that will run it —
     * the framework's own Foundation\Config\Env states the rule: "env access is a declared
     * reference, never an inline read". A declared reference is resolved at runtime instead, and
     * `env(NAME)` parameters supply the same fallback the `??` used to.
     *
     * Verified before changing: this app compiles its container in-process, so the old reads were
     * resolving correctly in production. This closes a latent failure, not an active one — the
     * moment a container is dumped at build time, inline reads freeze build-host values.
     */
    private function registerEnvDefaults(ContainerBuilder $container): void
    {
        foreach ([
            'ALERTS_ALLOW_INSECURE_WEBHOOK'    => '0',
            'ALERTS_REMINDER_INITIAL_SECONDS'  => '600',
            'ALERTS_REMINDER_MAX_SECONDS'      => '21600',
            'ALERTS_DEDUPE_WINDOW_SECONDS'     => '300',
            'ALERTS_RATE_LIMIT_PER_TENANT'     => '100',
            'ALERTS_RATE_LIMIT_GLOBAL'         => '1000',
            'ALERTS_RESOLVE_AFTER_SECONDS'     => '3600',
            'VORTOS_ALERTS_SPOOL_MAX_RECORDS'  => '10000',
            'APP_ENV'                          => 'prod',
            'VORTOS_CACHE_PREFIX'              => '',
        ] as $name => $default) {
            if (!$container->hasParameter('env(' . $name . ')')) {
                $container->setParameter('env(' . $name . ')', $default);
            }
        }
    }

    private function registerNotifierSeam(ContainerBuilder $container): void
    {
        $container->register(CollectNotifiersPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(NotifierRegistry::class, NotifierRegistry::class)
            ->setArgument('$drivers', new Reference(CollectNotifiersPass::LOCATOR_ID))
            ->setPublic(false);
    }

    private function registerDefaultDrivers(ContainerBuilder $container): void
    {
        $container->register(Client::class, Client::class)->setPublic(false);
        $container->setAlias(ClientInterface::class, Client::class)->setPublic(false);

        $container->register(HttpNotifierTransportInterface::class, GuzzleNotifierTransport::class)
            ->setArgument('$client', new Reference(ClientInterface::class))
            ->setPublic(false);

        $container->register(SsrfGuard::class, SsrfGuard::class)
            ->setArgument('$allowInsecureScheme', '%env(bool:ALERTS_ALLOW_INSECURE_WEBHOOK)%')
            ->setPublic(false);

        // Declared references, resolved at runtime. The default for the directory is computed
        // here only to seed the env() fallback; the value handed to the service is the placeholder.
        $container->setParameter('env(ALERTS_SPOOL_DIR)', sys_get_temp_dir() . '/vortos-alerts');
        $container->setParameter('env(ALERTS_SPOOL_MAX_BYTES)', (string) (64 * 1024 * 1024));
        $spoolDir = '%env(string:ALERTS_SPOOL_DIR)%';
        $spoolMaxBytes = '%env(int:ALERTS_SPOOL_MAX_BYTES)%';

        $this->registerDriver($container, 'slack', SlackNotifier::class, $spoolDir, $spoolMaxBytes, [
            '$transport' => new Reference(HttpNotifierTransportInterface::class),
            '$ssrfGuard' => new Reference(SsrfGuard::class),
        ]);
        $this->registerDriver($container, 'telegram', TelegramNotifier::class, $spoolDir, $spoolMaxBytes, [
            '$transport' => new Reference(HttpNotifierTransportInterface::class),
        ]);
        $this->registerDriver($container, 'webhook', WebhookNotifier::class, $spoolDir, $spoolMaxBytes, [
            '$transport' => new Reference(HttpNotifierTransportInterface::class),
            '$ssrfGuard' => new Reference(SsrfGuard::class),
        ]);
        $this->registerDriver($container, 'null', NullNotifier::class, $spoolDir, $spoolMaxBytes, []);

        // class_exists() only — deliberately NOT `&& $container->has(...)`.
        //
        // "Is vortos-aws-ses installed?" is order-free and is the whole question. "Has its
        // extension registered ImmediateMailer yet?" is a race during load(), and adds nothing:
        // AwsSesExtension registers ImmediateMailer unconditionally, and the Reference below is
        // resolved by the container after every extension has loaded, not here.
        if (class_exists(\Vortos\AwsSes\ImmediateMailer::class)) {
            $this->registerDriver($container, 'ses', \Vortos\Alerts\Notifier\Driver\Ses\SesNotifier::class, $spoolDir, $spoolMaxBytes, [
                '$mailer' => new Reference(\Vortos\AwsSes\ImmediateMailer::class),
            ]);
        }
    }

    /** @param array<string, mixed> $extraArgs */
    /**
     * @param string $spoolDir      an `%env(...)%` placeholder, resolved by the container at runtime
     * @param int|string $spoolMaxBytes  likewise — string when it is a placeholder, int in tests
     * @param array<string, mixed> $extraArgs
     */
    private function registerDriver(ContainerBuilder $container, string $key, string $driverClass, string $spoolDir, int|string $spoolMaxBytes, array $extraArgs): void
    {
        $innerId = $driverClass . '.inner';
        $definition = $container->register($innerId, $driverClass)->setPublic(false);
        foreach ($extraArgs as $arg => $value) {
            $definition->setArgument($arg, $value);
        }

        // SHARED SPOOL WHERE POSSIBLE. A file spool is private to one container, which is lossy the
        // moment there is more than one: blue-green destroys the retiring color (and any alert it had
        // queued for retry) on cutover, and an HTTP color running FrankenPHP has no drainer process at
        // all, so its spool is written and never read. Backing the queue with Redis makes it a
        // property of the system rather than of a process — one drainer anywhere flushes what any
        // container enqueued, and the queue outlives the process that wrote it. Falls back to the
        // file spool only when no Redis is configured (single-process/dev deploys).
        $spoolId = 'vortos.alerts.outbox_spool.' . $key;
        // COMPILE-TIME BY NECESSITY, unlike the argument reads above.
        //
        // This value does not configure a service, it decides WHICH service exists: a Redis-backed
        // spool shared across containers, or a per-process file spool. A %env()% placeholder cannot
        // choose a branch, because branches are taken while the container is built and placeholders
        // are only resolved after. Making this runtime-resolved means registering both spools and
        // selecting between them inside a service — a behavioural change, not a cleanup.
        //
        // Verified in production before leaving it: vortos.alerts.spool_redis IS registered, so the
        // Redis branch is being taken and alerts queued for retry survive a blue-green cutover.
        // This app compiles its container in-process, where these variables are present. It would
        // break if the container were ever dumped at image-build time.
        $redisDsn = (string) ($_ENV['VORTOS_ALERTS_SPOOL_DSN'] ?? $_ENV['VORTOS_CACHE_DSN'] ?? $_ENV['REDIS_DSN'] ?? '');

        if ($redisDsn !== '' && class_exists(\Redis::class) && class_exists(\Vortos\Cache\Adapter\RedisConnectionFactory::class)) {
            $redisId = 'vortos.alerts.spool_redis';
            if (!$container->hasDefinition($redisId)) {
                $container->register($redisId, \Redis::class)
                    ->setFactory([\Vortos\Cache\Adapter\RedisConnectionFactory::class, 'fromDsn'])
                    ->setArgument('$dsn', $redisDsn)
                    ->setPublic(false);
            }

            $container->register($spoolId, \Vortos\Observability\Buffer\RedisSpool::class)
                ->setArgument('$redis', new Reference($redisId))
                ->setArgument('$key', '%env(string:VORTOS_CACHE_PREFIX)%' . 'alerts:outbox:' . $key)
                ->setArgument('$maxRecords', '%env(int:VORTOS_ALERTS_SPOOL_MAX_RECORDS)%')
                ->setPublic(false);
        } else {
            $container->register($spoolId, BoundedSpool::class)
                ->setArgument('$path', $spoolDir . '/outbox-' . $key . '.spool')
                ->setArgument('$maxBytes', $spoolMaxBytes)
                ->setPublic(false);
        }

        $outboxId = 'vortos.alerts.notifier.' . $key;
        $container->register($outboxId, OutboxNotifier::class)
            ->setArgument('$inner', new Reference($innerId))
            ->setArgument('$spool', new Reference($spoolId))
            ->addTag(CollectNotifiersPass::TAG, ['key' => $key])
            ->setPublic(false);
    }

    private function registerRules(ContainerBuilder $container): void
    {
        // Alert rules are declared in config/alerts.php and assembled by the factory — no
        // service-definition override required (upstream P2-2).
        $container->register(\Vortos\Alerts\Rule\AlertRuleSetFactory::class, \Vortos\Alerts\Rule\AlertRuleSetFactory::class)
            ->setPublic(false);

        $container->register(AlertRuleSet::class, AlertRuleSet::class)
            ->setFactory([new Reference(\Vortos\Alerts\Rule\AlertRuleSetFactory::class), '__invoke'])
            ->setArguments(['%kernel.project_dir%'])
            ->setPublic(true);

        $container->register(AlertRuleValidator::class, AlertRuleValidator::class)->setPublic(false);
        $container->register(AlertRuleEvaluator::class, AlertRuleEvaluator::class)->setPublic(false);
    }

    private function registerDedupe(ContainerBuilder $container): void
    {
        // "Still firing" reminders back off instead of repeating on a fixed schedule. The old
        // ALERTS_DIGEST_EVERY counted OCCURRENCES, which — because sources are evaluated on a fixed
        // cadence — meant a reminder every ten minutes forever for any condition that does not
        // resolve itself. These are time based, so the schedule does not change if the evaluation
        // cadence does.
        $container->register(ReminderBackoff::class, ReminderBackoff::class)
            ->setArgument('$initialSeconds', '%env(int:ALERTS_REMINDER_INITIAL_SECONDS)%')
            ->setArgument('$maxSeconds', '%env(int:ALERTS_REMINDER_MAX_SECONDS)%')
            ->setPublic(false);

        $container->register(Dedupe::class, Dedupe::class)
            ->setArgument('$backoff', new Reference(ReminderBackoff::class))
            ->setPublic(false);

        $container->register(DedupeWindow::class, DedupeWindow::class)
            ->setArgument('$seconds', '%env(int:ALERTS_DEDUPE_WINDOW_SECONDS)%')
            ->setPublic(false);

        // Always the in-memory fallbacks here; DurableAlertStorePass upgrades them to DBAL when a
        // Connection exists. Choosing in load() meant asking has(Connection::class) before
        // vortos-persistence had necessarily registered it. That pass exists precisely because this
        // race was lost in production — dedupe state, acks and maintenance silences all silently
        // became per-process and did not survive a restart or span the blue/green colours.
        $container->register(InMemoryAlertStateStore::class, InMemoryAlertStateStore::class)->setPublic(false);
        $container->setAlias(AlertStateStoreInterface::class, InMemoryAlertStateStore::class)->setPublic(false);

        $container->register(InMemoryAckStore::class, InMemoryAckStore::class)->setPublic(false);
        $container->setAlias(AckStoreInterface::class, InMemoryAckStore::class)->setPublic(false);

        $container->register(InMemoryMaintenanceSilenceStore::class, InMemoryMaintenanceSilenceStore::class)->setPublic(false);
        $container->setAlias(MaintenanceSilenceStoreInterface::class, InMemoryMaintenanceSilenceStore::class)->setPublic(false);
    }

    private function registerRouting(ContainerBuilder $container): void
    {
        $container->setParameter('env(ALERTS_PAGING_DRIVER)', 'telegram');
        $container->setParameter('env(ALERTS_CHAT_DRIVER)', 'telegram');
        $pagingChannelDriver = '%env(string:ALERTS_PAGING_DRIVER)%';
        $chatChannelDriver = '%env(string:ALERTS_CHAT_DRIVER)%';

        // B21: inline Definitions, not object instances — the prod HTTP container is dumped via
        // PhpDumper, which cannot serialise a raw ChannelDefinition object argument.
        $container->register(ChannelRegistry::class, ChannelRegistry::class)
            ->setArgument('$channels', [
                new Definition(ChannelDefinition::class, ['eng-chat', $chatChannelDriver]),
                new Definition(ChannelDefinition::class, ['oncall-page', $pagingChannelDriver]),
            ])
            ->setPublic(true); // app config may override with additional channels

        $container->register(RoutingMatrix::class, RoutingMatrix::class)
            ->setFactory([RoutingMatrix::class, 'default'])
            ->setPublic(true); // app config may override with custom routing

        $container->register(Router::class, Router::class)
            ->setArgument('$matrix', new Reference(RoutingMatrix::class))
            ->setArgument('$channels', new Reference(ChannelRegistry::class))
            ->setPublic(false);
    }

    private function registerEscalation(ContainerBuilder $container): void
    {
        // B21: inline Definitions (Responder / DateTimeImmutable / EscalationTier), never raw objects.
        $container->register(OnCallRotation::class, OnCallRotation::class)
            ->setArgument('$responders', [new Definition(Responder::class, ['default-oncall', 'Default On-Call', 'oncall-page'])])
            ->setArgument('$epoch', new Definition(DateTimeImmutable::class, ['@0']))
            ->setPublic(true); // app config overrides with a real roster

        $container->register(EscalationPolicy::class, EscalationPolicy::class)
            ->setArgument('$tiers', [
                new Definition(EscalationTier::class, [0, 0]),
                new Definition(EscalationTier::class, [1, 900]),
            ])
            ->setPublic(true);

        $container->register(QuietHoursPolicy::class, QuietHoursPolicy::class)
            ->setArgument('$windows', [])
            ->setPublic(true);

        $container->register(EscalationEngine::class, EscalationEngine::class)
            ->setArgument('$policy', new Reference(EscalationPolicy::class))
            ->setArgument('$rotation', new Reference(OnCallRotation::class))
            ->setArgument('$quietHours', new Reference(QuietHoursPolicy::class))
            ->setPublic(false);

        // Compile-time: decides whether AckTokenSigner is registered at all. Same shape as the
        // audit recorder in FB-36. Verified present in production (AckTokenSigner is in the
        // container), so acknowledging an alert works; left as-is because registering an unusable
        // signer needs a runtime is-operational seam on the class, not a placeholder swap.
        $ackHmacKey = (string) ($_ENV['ALERTS_ACK_HMAC_KEY'] ?? '');
        if ($ackHmacKey !== '') {
            $container->register(AckTokenSigner::class, AckTokenSigner::class)
                ->setArgument('$hmacKey', $ackHmacKey)
                ->setPublic(true);
        }
    }

    private function registerDispatcher(ContainerBuilder $container): void
    {
        $container->register(OutboundRateLimitConfig::class, OutboundRateLimitConfig::class)
            ->setArgument('$perTenantPerHour', '%env(int:ALERTS_RATE_LIMIT_PER_TENANT)%')
            ->setArgument('$globalPerHour', '%env(int:ALERTS_RATE_LIMIT_GLOBAL)%')
            ->setArgument('$perChannelKindPerHour', [])
            ->setPublic(false);

        $container->register(SlidingWindowOutboundRateLimiter::class, SlidingWindowOutboundRateLimiter::class)
            ->setArgument('$config', new Reference(OutboundRateLimitConfig::class))
            ->setPublic(false);
        $container->setAlias(OutboundRateLimiterInterface::class, SlidingWindowOutboundRateLimiter::class)->setPublic(false);

        $container->register(AlertDispatcher::class, AlertDispatcher::class)
            ->setArgument('$dedupe', new Reference(Dedupe::class))
            ->setArgument('$stateStore', new Reference(AlertStateStoreInterface::class))
            ->setArgument('$window', new Reference(DedupeWindow::class))
            ->setArgument('$router', new Reference(Router::class))
            ->setArgument('$notifiers', new Reference(NotifierRegistry::class))
            ->setArgument('$rateLimiter', new Reference(OutboundRateLimiterInterface::class))
            ->setArgument('$auditRecorder', new Reference(AlertAuditRecorderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->setAlias(AlertDispatcherInterface::class, AlertDispatcher::class)->setPublic(false);
    }

    private function registerSlo(ContainerBuilder $container): void
    {
        // The SloRegistry fallback (when vortos-observability is absent) is registered by
        // SloRegistryDefaultPass — a cross-package "register default if absent" decision that
        // must run in a compiler pass, not here in load().

        $container->register(NullSloBurnRateProvider::class, NullSloBurnRateProvider::class)->setPublic(false);
        $container->setAlias(SloBurnRateProviderInterface::class, NullSloBurnRateProvider::class)->setPublic(false);

        $container->register(SloBurnAlertSource::class, SloBurnAlertSource::class)
            ->setArgument('$sloRegistry', new Reference(SloRegistry::class))
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setArgument('$provider', new Reference(SloBurnRateProviderInterface::class))
            ->addTag(self::SOURCE_TAG)
            ->setPublic(true);
    }

    /**
     * Queue backlog (outbox depth, DLQ depth, oldest stuck message) as an alertable signal.
     *
     * The source is always registered; it simply has nothing to evaluate until some package
     * implements {@see QueueBacklogProviderInterface} — vortos-messaging ships providers for the
     * outbox and dead-letter tables.
     */
    private function registerQueueBacklogIntegration(ContainerBuilder $container): void
    {
        $container->register(QueueBacklogAlertSource::class, QueueBacklogAlertSource::class)
            ->setArgument('$providers', new TaggedIteratorArgument(self::BACKLOG_PROVIDER_TAG))
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->addTag(self::SOURCE_TAG)
            ->setPublic(true);

        $container->registerForAutoconfiguration(QueueBacklogProviderInterface::class)
            ->addTag(self::BACKLOG_PROVIDER_TAG);
    }

    /**
     * The driver the alerting pipeline was missing: something that actually calls tick().
     *
     * The scheduled command is wired only when vortos-scheduler and vortos-cqrs are both installed.
     * Without them the ticker is still available for an app to drive from its own cron — but
     * nothing fires by itself, which is the state this whole seam exists to end.
     */
    private function registerSourceTicker(ContainerBuilder $container): void
    {
        $container->register(AlertSourceTicker::class, AlertSourceTicker::class)
            ->setArgument('$sources', new TaggedIteratorArgument(self::SOURCE_TAG))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)
            ->setPublic(true);

        if (!interface_exists(CommandBusInterface::class) || !class_exists(StaticSchedulePass::class)) {
            return;
        }

        $container->register(EvaluateAlertSourcesCommand::class, EvaluateAlertSourcesCommand::class)
            ->setPublic(false);

        $container->register(StaleAlertResolver::class, StaleAlertResolver::class)
            ->setArgument('$store', new Reference(AlertStateStoreInterface::class))
            ->setArgument('$silenceSeconds', '%env(int:ALERTS_RESOLVE_AFTER_SECONDS)%')
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->register(EvaluateAlertSourcesHandler::class, EvaluateAlertSourcesHandler::class)
            ->setArgument('$ticker', new Reference(AlertSourceTicker::class))
            ->setArgument('$staleResolver', new Reference(StaleAlertResolver::class))
            ->setArgument('$clock', new Reference(ClockInterface::class))
            ->setArgument('$env', '%env(string:APP_ENV)%')
            ->addTag('vortos.command_handler')
            ->setPublic(true);

        $container->register(EvaluateAlertSourcesSchedule::class, EvaluateAlertSourcesSchedule::class)
            ->addTag(StaticSchedulePass::TAG)
            ->setPublic(false);
    }

    /**
     * Health-derived alert sources are registered by
     * {@see \Vortos\Alerts\DependencyInjection\Compiler\AlertsHealthIntegrationPass}.
     *
     * They used to be registered here, gated on
     * `class_exists(HealthProbeRegistry) && $container->has(HealthProbeRegistry)`. The has() is a
     * race against extension load order, and losing it silently dropped every health-derived
     * source — probe failures, capacity, cert expiry and synthetic uptime all stop being
     * evaluated, with nothing to report the gap.
     */

    private function registerBackupIntegration(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Backup\Event\BackupEventSinkInterface::class)) {
            return;
        }

        $container->register(\Vortos\Alerts\Integration\Backup\BackupEventAlertSink::class, \Vortos\Alerts\Integration\Backup\BackupEventAlertSink::class)
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(false);
    }

    private function registerDeployIntegration(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Deploy\Audit\DeployAuditSinkInterface::class)) {
            return;
        }

        $container->register(\Vortos\Alerts\Integration\Deploy\DeployAuditAlertSink::class, \Vortos\Alerts\Integration\Deploy\DeployAuditAlertSink::class)
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(false);

        if (interface_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            $container->register(AlertRulesDoctorCheck::class, AlertRulesDoctorCheck::class)
                ->setArgument('$rules', new Reference(AlertRuleSet::class))
                ->setArgument('$validator', new Reference(AlertRuleValidator::class))
                ->setArgument('$sloRegistry', new Reference(SloRegistry::class))
                ->setPublic(false);
        }
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $container->register(ValidateRulesCommand::class, ValidateRulesCommand::class)
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$validator', new Reference(AlertRuleValidator::class))
            ->setArgument('$sloRegistry', new Reference(SloRegistry::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(TestAlertCommand::class, TestAlertCommand::class)
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(SupervisorEventListenerCommand::class, SupervisorEventListenerCommand::class)
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(true)
            ->addTag('console.command');

        if ($container->hasDefinition(AckTokenSigner::class)) {
            $container->register(AckCommand::class, AckCommand::class)
                ->setArgument('$signer', new Reference(AckTokenSigner::class))
                ->setArgument('$ackStore', new Reference(AckStoreInterface::class))
                ->setPublic(true)
                ->addTag('console.command');
        }

        $outboxIds = [];
        foreach (['slack', 'telegram', 'webhook', 'null', 'ses'] as $key) {
            $id = 'vortos.alerts.notifier.' . $key;
            if ($container->hasDefinition($id)) {
                $outboxIds[] = new Reference($id);
            }
        }
        $container->register(DrainCommand::class, DrainCommand::class)
            ->setArgument('$outboxes', $outboxIds)
            ->setPublic(true)
            ->addTag('console.command');

        // The outbox retains failed deliveries for retry, which is worthless unless something drains
        // it — and nothing did, so a transient webhook failure meant a permanently lost alert. Ship
        // the drainer as a supervised worker program so the retry path exists by default rather than
        // depending on every deploy remembering to wire it. Guarded on vortos-docker being installed
        // (vortos-alerts must not hard-depend on it) and on the same opt-in flag the other supervised
        // workers use, so a non-containerized deploy is unaffected.
        if (class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)
            && filter_var($_ENV['VORTOS_ALERTS_DRAIN_SUPERVISED'] ?? true, FILTER_VALIDATE_BOOL)
        ) {
            $container->register('vortos.alerts.drain_process', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArgument('$name', 'alerts-drain')
                ->setArgument('$command', \Vortos\Docker\Worker\WorkerConsole::command('vortos:alerts:drain --loop --interval=30'))
                ->setArgument('$description', 'Vortos alert delivery outbox drainer (retry path for failed notifications)')
                ->setArgument('$stopwaitsecs', 30)
                ->setArgument('$drainDeadline', 10)
                ->addTag(\Vortos\Docker\DependencyInjection\DockerExtension::WORKER_TAG)
                ->setPublic(false);
        }

        $container->register(RotationShowCommand::class, RotationShowCommand::class)
            ->setArgument('$rotation', new Reference(OnCallRotation::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(SilenceCommand::class, SilenceCommand::class)
            ->setArgument('$store', new Reference(MaintenanceSilenceStoreInterface::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
