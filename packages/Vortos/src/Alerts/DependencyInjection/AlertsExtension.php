<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Alerts\AlertDispatcher;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Console\AckCommand;
use Vortos\Alerts\Console\DrainCommand;
use Vortos\Alerts\Console\RotationShowCommand;
use Vortos\Alerts\Console\SilenceCommand;
use Vortos\Alerts\Console\TestAlertCommand;
use Vortos\Alerts\Console\ValidateRulesCommand;
use Vortos\Alerts\Dedupe\AlertStateStoreInterface;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DbalAlertStateStore;
use Vortos\Alerts\Dedupe\DedupeWindow;
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
    public function getAlias(): string
    {
        return 'vortos_alerts';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->registerNotifierSeam($container);
        $this->registerDefaultDrivers($container);
        $this->registerRules($container);
        $this->registerDedupe($container);
        $this->registerRouting($container);
        $this->registerEscalation($container);
        $this->registerDispatcher($container);
        $this->registerSlo($container);
        $this->registerHealthIntegration($container);
        $this->registerBackupIntegration($container);
        $this->registerDeployIntegration($container);
        $this->registerAudit($container);
        $this->registerCommands($container);

        $container->registerForAutoconfiguration(NotifierInterface::class)
            ->addTag(CollectNotifiersPass::TAG);
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
            ->setArgument('$allowInsecureScheme', (bool) ($_ENV['ALERTS_ALLOW_INSECURE_WEBHOOK'] ?? false))
            ->setPublic(false);

        $spoolDir = (string) ($_ENV['ALERTS_SPOOL_DIR'] ?? sys_get_temp_dir() . '/vortos-alerts');
        $spoolMaxBytes = (int) ($_ENV['ALERTS_SPOOL_MAX_BYTES'] ?? 64 * 1024 * 1024);

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

        if (class_exists(\Vortos\AwsSes\ImmediateMailer::class) && $container->has(\Vortos\AwsSes\ImmediateMailer::class)) {
            $this->registerDriver($container, 'ses', \Vortos\Alerts\Notifier\Driver\Ses\SesNotifier::class, $spoolDir, $spoolMaxBytes, [
                '$mailer' => new Reference(\Vortos\AwsSes\ImmediateMailer::class),
            ]);
        }
    }

    /** @param array<string, mixed> $extraArgs */
    private function registerDriver(ContainerBuilder $container, string $key, string $driverClass, string $spoolDir, int $spoolMaxBytes, array $extraArgs): void
    {
        $innerId = $driverClass . '.inner';
        $definition = $container->register($innerId, $driverClass)->setPublic(false);
        foreach ($extraArgs as $arg => $value) {
            $definition->setArgument($arg, $value);
        }

        $spoolId = 'vortos.alerts.outbox_spool.' . $key;
        $container->register($spoolId, BoundedSpool::class)
            ->setArgument('$path', $spoolDir . '/outbox-' . $key . '.spool')
            ->setArgument('$maxBytes', $spoolMaxBytes)
            ->setPublic(false);

        $outboxId = 'vortos.alerts.notifier.' . $key;
        $container->register($outboxId, OutboxNotifier::class)
            ->setArgument('$inner', new Reference($innerId))
            ->setArgument('$spool', new Reference($spoolId))
            ->addTag(CollectNotifiersPass::TAG, ['key' => $key])
            ->setPublic(false);
    }

    private function registerRules(ContainerBuilder $container): void
    {
        $container->register(AlertRuleSet::class, AlertRuleSet::class)
            ->setArgument('$rules', [])
            ->setPublic(true); // app config overrides this definition with its declared rules

        $container->register(AlertRuleValidator::class, AlertRuleValidator::class)->setPublic(false);
        $container->register(AlertRuleEvaluator::class, AlertRuleEvaluator::class)->setPublic(false);
    }

    private function registerDedupe(ContainerBuilder $container): void
    {
        $container->register(Dedupe::class, Dedupe::class)
            ->setArgument('$digestEvery', (int) ($_ENV['ALERTS_DIGEST_EVERY'] ?? 10))
            ->setPublic(false);

        $container->register(DedupeWindow::class, DedupeWindow::class)
            ->setArgument('$seconds', (int) ($_ENV['ALERTS_DEDUPE_WINDOW_SECONDS'] ?? 300))
            ->setPublic(false);

        if ($container->has(Connection::class)) {
            $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
                ? $container->getParameter('vortos.db.framework_table_prefix')
                : 'vortos_';

            $container->register(DbalAlertStateStore::class, DbalAlertStateStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'alerts_state')
                ->setPublic(false);
            $container->setAlias(AlertStateStoreInterface::class, DbalAlertStateStore::class)->setPublic(false);

            $container->register(DbalAckStore::class, DbalAckStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'alerts_acks')
                ->setPublic(false);
            $container->setAlias(AckStoreInterface::class, DbalAckStore::class)->setPublic(false);

            $container->register(DbalMaintenanceSilenceStore::class, DbalMaintenanceSilenceStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'alerts_silences')
                ->setPublic(false);
            $container->setAlias(MaintenanceSilenceStoreInterface::class, DbalMaintenanceSilenceStore::class)->setPublic(false);
        } else {
            $container->register(InMemoryAlertStateStore::class, InMemoryAlertStateStore::class)->setPublic(false);
            $container->setAlias(AlertStateStoreInterface::class, InMemoryAlertStateStore::class)->setPublic(false);

            $container->register(InMemoryAckStore::class, InMemoryAckStore::class)->setPublic(false);
            $container->setAlias(AckStoreInterface::class, InMemoryAckStore::class)->setPublic(false);

            $container->register(InMemoryMaintenanceSilenceStore::class, InMemoryMaintenanceSilenceStore::class)->setPublic(false);
            $container->setAlias(MaintenanceSilenceStoreInterface::class, InMemoryMaintenanceSilenceStore::class)->setPublic(false);
        }
    }

    private function registerRouting(ContainerBuilder $container): void
    {
        $pagingChannelDriver = (string) ($_ENV['ALERTS_PAGING_DRIVER'] ?? 'telegram');
        $chatChannelDriver = (string) ($_ENV['ALERTS_CHAT_DRIVER'] ?? 'telegram');

        $container->register(ChannelRegistry::class, ChannelRegistry::class)
            ->setArgument('$channels', [
                new ChannelDefinition('eng-chat', $chatChannelDriver),
                new ChannelDefinition('oncall-page', $pagingChannelDriver),
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
        $container->register(OnCallRotation::class, OnCallRotation::class)
            ->setArgument('$responders', [new Responder('default-oncall', 'Default On-Call', 'oncall-page')])
            ->setArgument('$epoch', new DateTimeImmutable('@0'))
            ->setPublic(true); // app config overrides with a real roster

        $container->register(EscalationPolicy::class, EscalationPolicy::class)
            ->setArgument('$tiers', [
                new EscalationTier(0, 0),
                new EscalationTier(1, 900),
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
            ->setArgument('$perTenantPerHour', (int) ($_ENV['ALERTS_RATE_LIMIT_PER_TENANT'] ?? 100))
            ->setArgument('$globalPerHour', (int) ($_ENV['ALERTS_RATE_LIMIT_GLOBAL'] ?? 1000))
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
            ->setPublic(false);

        $container->setAlias(AlertDispatcherInterface::class, AlertDispatcher::class)->setPublic(false);
    }

    private function registerSlo(ContainerBuilder $container): void
    {
        if (!$container->has(SloRegistry::class)) {
            $container->register(SloRegistry::class, SloRegistry::class)
                ->setArgument('$slos', [])
                ->setPublic(true); // app config overrides with declared SLOs
        }

        $container->register(NullSloBurnRateProvider::class, NullSloBurnRateProvider::class)->setPublic(false);
        $container->setAlias(SloBurnRateProviderInterface::class, NullSloBurnRateProvider::class)->setPublic(false);

        $container->register(SloBurnAlertSource::class, SloBurnAlertSource::class)
            ->setArgument('$sloRegistry', new Reference(SloRegistry::class))
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setArgument('$provider', new Reference(SloBurnRateProviderInterface::class))
            ->setPublic(true);
    }

    private function registerHealthIntegration(ContainerBuilder $container): void
    {
        if (!class_exists(\Vortos\Health\Probe\HealthProbeRegistry::class) || !$container->has(\Vortos\Health\Probe\HealthProbeRegistry::class)) {
            return;
        }

        $container->register(\Vortos\Alerts\Integration\Health\HealthProbeAlertSource::class, \Vortos\Alerts\Integration\Health\HealthProbeAlertSource::class)
            ->setArgument('$probes', new Reference(\Vortos\Health\Probe\HealthProbeRegistry::class))
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(true);

        // Block 18: capacity (disk/RAM/CPU) and cert-expiry probes are ordinary
        // HealthProbeInterface readiness probes, so they reuse HealthProbeRegistry —
        // no new Health-side dependency, same guard as HealthProbeAlertSource above.
        $container->register(\Vortos\Alerts\Integration\Health\CapacityAlertSource::class, \Vortos\Alerts\Integration\Health\CapacityAlertSource::class)
            ->setArgument('$probes', new Reference(\Vortos\Health\Probe\HealthProbeRegistry::class))
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(true);

        $container->register(\Vortos\Alerts\Integration\Health\CertExpiryAlertSource::class, \Vortos\Alerts\Integration\Health\CertExpiryAlertSource::class)
            ->setArgument('$probes', new Reference(\Vortos\Health\Probe\HealthProbeRegistry::class))
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setPublic(true);

        if (class_exists(\Vortos\Health\Uptime\UptimeMonitorRegistry::class) && $container->has(\Vortos\Health\Uptime\UptimeMonitorRegistry::class)) {
            $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
                ? $container->getParameter('vortos.db.framework_table_prefix')
                : 'vortos_';

            if ($container->has(Connection::class)) {
                $container->register(\Vortos\Alerts\Integration\Health\DbalUptimeUnknownStreakStore::class, \Vortos\Alerts\Integration\Health\DbalUptimeUnknownStreakStore::class)
                    ->setArgument('$connection', new Reference(Connection::class))
                    ->setArgument('$table', $prefix . 'alerts_uptime_streaks')
                    ->setPublic(false);
                $container->setAlias(\Vortos\Alerts\Integration\Health\UptimeUnknownStreakStoreInterface::class, \Vortos\Alerts\Integration\Health\DbalUptimeUnknownStreakStore::class)
                    ->setPublic(false);
            } else {
                $container->register(\Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore::class, \Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore::class)
                    ->setPublic(false);
                $container->setAlias(\Vortos\Alerts\Integration\Health\UptimeUnknownStreakStoreInterface::class, \Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore::class)
                    ->setPublic(false);
            }

            $container->register(\Vortos\Alerts\Integration\Health\SyntheticUptimeAlertSource::class, \Vortos\Alerts\Integration\Health\SyntheticUptimeAlertSource::class)
                ->setArgument('$monitors', new Reference(\Vortos\Health\Uptime\UptimeMonitorRegistry::class))
                ->setArgument('$monitorDriverKey', (string) ($_ENV['ALERTS_UPTIME_MONITOR_DRIVER'] ?? 'null'))
                ->setArgument('$rules', new Reference(AlertRuleSet::class))
                ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
                ->setArgument('$streaks', new Reference(\Vortos\Alerts\Integration\Health\UptimeUnknownStreakStoreInterface::class))
                ->setArgument('$blindDetectorThreshold', (int) ($_ENV['ALERTS_UPTIME_BLIND_DETECTOR_THRESHOLD'] ?? 3))
                ->setPublic(true);
        }
    }

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

    private function registerAudit(ContainerBuilder $container): void
    {
        if (!$container->has(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalAlertAuditViewRepository::class, DbalAlertAuditViewRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'alerts_audit_log')
            ->setPublic(false);
        $container->setAlias(AlertAuditViewRepositoryInterface::class, DbalAlertAuditViewRepository::class)->setPublic(false);

        if (!$container->hasDefinition(AuditHashChain::class)) {
            $container->register(AuditHashChain::class, AuditHashChain::class)->setPublic(false);
        }

        $hmacKey = (string) ($_ENV['ALERTS_AUDIT_HMAC_KEY'] ?? '');
        if ($hmacKey !== '') {
            $container->register(AlertAuditRecorder::class, AlertAuditRecorder::class)
                ->setArgument('$repository', new Reference(AlertAuditViewRepositoryInterface::class))
                ->setArgument('$chain', new Reference(AuditHashChain::class))
                ->setArgument('$hmacKey', $hmacKey)
                ->setPublic(true);
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
