<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection;

use Aws\SesV2\SesV2Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\Contract\ImmediateMailerInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Driver\Log\LogMailer;
use Vortos\AwsSes\Driver\Null\NullMailer;
use Vortos\AwsSes\Driver\Ses\Health\SesHealthCheck;
use Vortos\AwsSes\Driver\Ses\SesClientFactory;
use Vortos\AwsSes\Driver\Ses\SesMailer;
use Vortos\Make\Engine\GeneratorEngine;
use Vortos\AwsSes\Command\Make\MakeBounceHandlerCommand;
use Vortos\AwsSes\Command\Make\MakeComplaintHandlerCommand;
use Vortos\AwsSes\Command\Make\MakeSesEmailMiddlewareCommand;
use Vortos\AwsSes\Command\SesQuotaCommand;
use Vortos\AwsSes\Command\SesSendTestCommand;
use Vortos\AwsSes\Command\SesSuppressionListCommand;
use Vortos\AwsSes\Failover\CircuitBreaker;
use Vortos\AwsSes\Middleware\AuditLogMiddleware;
use Vortos\AwsSes\Failover\MultiRegionMailer;
use Vortos\AwsSes\Webhook\SignatureVerifierInterface;
use Vortos\AwsSes\Webhook\SnsSignatureVerifier;
use Vortos\AwsSes\Webhook\SnsWebhookController;
use Vortos\AwsSes\Bounce\AutoSuppressionBounceHandler;
use Vortos\AwsSes\Bounce\AutoSuppressionComplaintHandler;
use Vortos\AwsSes\Bounce\BounceHandlerRunner;
use Vortos\AwsSes\Bounce\ComplaintHandlerRunner;
use Vortos\AwsSes\Command\EmailOutboxRelayCommand;
use Vortos\AwsSes\Command\SuppressionSyncCommand;
use Vortos\AwsSes\Contract\BounceHandlerInterface;
use Vortos\AwsSes\Contract\ComplaintHandlerInterface;
use Vortos\AwsSes\Contract\EmailOutboxWriterInterface;
use Vortos\AwsSes\Contract\StandaloneMailerInterface;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Contract\TemplateRendererInterface;
use Vortos\AwsSes\Template\NullTemplateRenderer;
use Vortos\AwsSes\Template\PhpTemplateRenderer;
use Vortos\AwsSes\Outbox\EmailOutboxRelay;
use Vortos\AwsSes\Outbox\EmailOutboxWriter;
use Vortos\AwsSes\Outbox\StandaloneMailer;
use Vortos\AwsSes\Outbox\TransactionalOutboxMailer;
use Vortos\AwsSes\ImmediateMailer;
use Psr\SimpleCache\CacheInterface;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\AwsSes\Deduplication\DeduplicationStoreInterface;
use Vortos\AwsSes\Deduplication\InMemoryDeduplicationStore;
use Vortos\AwsSes\Deduplication\RedisDeduplicationStore;
use Vortos\AwsSes\Middleware\DeduplicationMiddleware;
use Vortos\AwsSes\Middleware\EmailMiddlewareStack;
use Vortos\AwsSes\Middleware\HookMiddleware;
use Vortos\AwsSes\Middleware\LoggingMiddleware;
use Vortos\AwsSes\Middleware\MetricsMiddleware;
use Vortos\AwsSes\Middleware\RateLimitMiddleware;
use Vortos\AwsSes\Middleware\SuppressionCheckMiddleware;
use Vortos\AwsSes\Middleware\TracingMiddleware;
use Vortos\AwsSes\RateLimit\InMemoryTokenBucket;
use Vortos\AwsSes\RateLimit\RedisTokenBucket;
use Vortos\AwsSes\RateLimit\TokenBucketInterface;
use Vortos\AwsSes\Suppression\DbalSuppressionList;
use Vortos\AwsSes\Suppression\OnSuppressed;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wires all SES services into the container.
 *
 * Loads config/aws_ses.php then config/{env}/aws_ses.php (env file overrides base).
 * All config nodes have defaults — no config file is required for basic usage.
 *
 * ## Services registered
 *
 *   NullMailer             — always registered
 *   LogMailer              — always registered
 *   SesV2Client            — registered when driver=ses
 *   SesMailer              — registered when driver=ses
 *   SesHealthCheck         — registered when driver=ses, auto-discovered by HealthCheckPass
 *   MailerInterface        — alias to active driver
 *   ... (services added per phase)
 */
final class AwsSesExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_aws_ses';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $vortosConfig = new VortosAwsSesConfig();

        $base = $projectDir . '/config/aws_ses.php';
        if (file_exists($base)) {
            (require $base)($vortosConfig);
        }

        $envFile = $projectDir . '/config/' . $env . '/aws_ses.php';
        if (file_exists($envFile)) {
            (require $envFile)($vortosConfig);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$vortosConfig->toArray()]);

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
        $resolved['outbox']['table_name']      = $prefix . $resolved['outbox']['table_name'];
        $resolved['suppression']['table_name'] = $prefix . $resolved['suppression']['table_name'];
        $resolved['audit_log']['table_name']   = $prefix . $resolved['audit_log']['table_name'];

        $this->setParameters($container, $resolved);
        $this->registerDrivers($container, $resolved);
        $this->registerMiddlewareStack($container, $resolved);
        $this->registerSuppression($container, $resolved);
        $this->registerRateLimitAndDeduplication($container, $resolved);
        $this->registerOutbox($container, $resolved);
        $this->registerTemplateRenderer($container, $resolved);
        $this->registerBounceAndComplaint($container);
        $this->registerWebhook($container, $resolved);
        $this->registerObservability($container, $resolved);
    }

    private function registerDrivers(ContainerBuilder $container, array $c): void
    {
        $container->register(NullMailer::class, NullMailer::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(LogMailer::class, LogMailer::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setShared(true)
            ->setPublic(false);

        if ($c['driver'] === 'ses') {
            $container->register(SesV2Client::class, SesV2Client::class)
                ->setFactory([SesClientFactory::class, 'create'])
                ->setArguments([
                    $c['region'],
                    $c['aws_client']['endpoint_override'],
                    $c['aws_client']['http_timeout'],
                    $c['aws_client']['max_retries'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register(SesMailer::class, SesMailer::class)
                ->setArguments([
                    new Reference(SesV2Client::class),
                    $c['region'],
                    $c['default_from_address'],
                    $c['default_from_name'],
                    $c['reply_to'],
                    $c['configuration_set'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register(SesHealthCheck::class, SesHealthCheck::class)
                ->setArgument('$client', new Reference(SesV2Client::class))
                ->setShared(true)
                ->setPublic(false);

            if ($c['fallback_region'] !== null) {
                $this->registerFallbackSesClient($container, $c);
            }
        }

        if ($c['driver'] === 'ses' && $c['fallback_region'] !== null) {
            $container->register('vortos_aws_ses.primary_circuit_breaker', CircuitBreaker::class)
                ->setArguments([
                    $c['circuit_breaker']['failure_threshold'],
                    $c['circuit_breaker']['reset_timeout_seconds'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register('vortos_aws_ses.fallback_circuit_breaker', CircuitBreaker::class)
                ->setArguments([
                    $c['circuit_breaker']['failure_threshold'],
                    $c['circuit_breaker']['reset_timeout_seconds'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register(MultiRegionMailer::class, MultiRegionMailer::class)
                ->setArguments([
                    new Reference(SesMailer::class),
                    new Reference('vortos_aws_ses.fallback_mailer'),
                    new Reference('vortos_aws_ses.primary_circuit_breaker'),
                    new Reference('vortos_aws_ses.fallback_circuit_breaker'),
                    new Reference(LoggerInterface::class),
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias('vortos_aws_ses.driver', MultiRegionMailer::class)->setPublic(false);
        } else {
            $driverClass = match ($c['driver']) {
                'ses'   => SesMailer::class,
                'log'   => LogMailer::class,
                default => NullMailer::class,
            };

            $container->setAlias('vortos_aws_ses.driver', $driverClass)->setPublic(false);
        }
    }

    private function registerMiddlewareStack(ContainerBuilder $container, array $c): void
    {
        $container->registerForAutoconfiguration(EmailMiddlewareInterface::class)
            ->addTag('vortos_aws_ses.email_middleware');

        if ($c['observability']['logging']) {
            $container->register(LoggingMiddleware::class, LoggingMiddleware::class)
                ->setArgument('$logger', new Reference(LoggerInterface::class))
                ->setArgument('$disabledSections', $c['observability']['logging_disabled_for'])
                ->addTag('vortos_aws_ses.email_middleware', ['priority' => 900])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($c['observability']['tracing'] && interface_exists(TracingInterface::class)) {
            $container->register(TracingMiddleware::class, TracingMiddleware::class)
                ->setArgument('$tracer', new Reference(TracingInterface::class))
                ->setArgument('$disabledSections', $c['observability']['tracing_disabled_for'])
                ->addTag('vortos_aws_ses.email_middleware', ['priority' => 800])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($c['observability']['metrics'] && interface_exists(MetricsInterface::class)) {
            $container->register(MetricsMiddleware::class, MetricsMiddleware::class)
                ->setArgument('$metrics', new Reference(MetricsInterface::class))
                ->setArgument('$disabledSections', $c['observability']['metrics_disabled_for'])
                ->addTag('vortos_aws_ses.email_middleware', ['priority' => 650])
                ->setShared(true)
                ->setPublic(false);
        }

        // Built-in: HookMiddleware (priority 700) — observers injected by MiddlewareCompilerPass
        $container->register(HookMiddleware::class, HookMiddleware::class)
            ->setArgument('$observers', [])
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->addTag('vortos_aws_ses.email_middleware', ['priority' => 700])
            ->setShared(true)
            ->setPublic(false);

        // MiddlewareCompilerPass populates $middlewares in priority order
        $container->register(EmailMiddlewareStack::class, EmailMiddlewareStack::class)
            ->setArgument('$driver', new Reference('vortos_aws_ses.driver'))
            ->setArgument('$middlewares', [])
            ->setShared(true)
            ->setPublic(false);

        // vortos_aws_ses.sending_mailer — the real network-bound stack.
        // EmailOutboxRelay injects this directly to avoid recursion when
        // MailerInterface is redirected through TransactionalOutboxMailer.
        $container->setAlias('vortos_aws_ses.sending_mailer', EmailMiddlewareStack::class)->setPublic(false);

        $container->register(ImmediateMailer::class, ImmediateMailer::class)
            ->setArgument('$inner', new Reference('vortos_aws_ses.sending_mailer'))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(ImmediateMailerInterface::class, ImmediateMailer::class)->setPublic(false);
    }

    private function registerBounceAndComplaint(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(BounceHandlerInterface::class)
            ->addTag('vortos_aws_ses.bounce_handler');
        $container->registerForAutoconfiguration(ComplaintHandlerInterface::class)
            ->addTag('vortos_aws_ses.complaint_handler');

        $container->register(AutoSuppressionBounceHandler::class, AutoSuppressionBounceHandler::class)
            ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(AutoSuppressionComplaintHandler::class, AutoSuppressionComplaintHandler::class)
            ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setShared(true)
            ->setPublic(false);

        // Runners — BounceHandlerDiscoveryPass / ComplaintHandlerDiscoveryPass populate $handlers
        $container->register(BounceHandlerRunner::class, BounceHandlerRunner::class)
            ->setArgument('$handlers', [new Reference(AutoSuppressionBounceHandler::class)])
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(ComplaintHandlerRunner::class, ComplaintHandlerRunner::class)
            ->setArgument('$handlers', [new Reference(AutoSuppressionComplaintHandler::class)])
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerTemplateRenderer(ContainerBuilder $container, array $c): void
    {
        if ($c['template_dir'] !== null) {
            $container->register(PhpTemplateRenderer::class, PhpTemplateRenderer::class)
                ->setArgument('$templateDir', $c['template_dir'])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias(TemplateRendererInterface::class, PhpTemplateRenderer::class)->setPublic(false);
        } else {
            $container->register(NullTemplateRenderer::class, NullTemplateRenderer::class)
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias(TemplateRendererInterface::class, NullTemplateRenderer::class)->setPublic(false);
        }
    }

    private function registerOutbox(ContainerBuilder $container, array $c): void
    {
        if (!$c['outbox']['enabled']) {
            $container->setAlias(MailerInterface::class, EmailMiddlewareStack::class)->setPublic(false);
            return;
        }

        $container->register(EmailOutboxWriter::class, EmailOutboxWriter::class)
            ->setArgument('$connection', new Reference(\Doctrine\DBAL\Connection::class))
            ->setArgument('$tableName',  $c['outbox']['table_name'])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(EmailOutboxWriterInterface::class, EmailOutboxWriter::class)->setPublic(false);

        $container->register(TransactionalOutboxMailer::class, TransactionalOutboxMailer::class)
            ->setArgument('$writer', new Reference(EmailOutboxWriterInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(MailerInterface::class, TransactionalOutboxMailer::class)->setPublic(false);

        $container->register(StandaloneMailer::class, StandaloneMailer::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference(MailerInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(StandaloneMailerInterface::class, StandaloneMailer::class)->setPublic(false);

        // Relay injects vortos_aws_ses.sending_mailer (EmailMiddlewareStack) directly —
        // NOT MailerInterface — to avoid writing back into the outbox in a loop.
        $container->register(EmailOutboxRelay::class, EmailOutboxRelay::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference('vortos_aws_ses.sending_mailer'),
                new Reference(LoggerInterface::class),
                $c['outbox']['table_name'],
                $c['outbox']['batch_size'],
                $c['outbox']['max_delivery_attempts'],
                $c['outbox']['backoff_base_seconds'],
                $c['outbox']['backoff_cap_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(EmailOutboxRelayCommand::class, EmailOutboxRelayCommand::class)
            ->setArguments([
                new Reference(EmailOutboxRelay::class),
                new Reference(LoggerInterface::class),
                $c['outbox']['sleep_seconds_when_empty'],
            ])
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        if (class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)) {
            $container->register('vortos_aws_ses.worker.aws_ses_outbox_relay', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArguments([
                    'aws-ses-outbox-relay',
                    'php /var/www/html/bin/console vortos:ses:outbox:relay',
                    'Relay pending SES outbox emails.',
                ])
                ->addTag('vortos.worker')
                ->setPublic(false);
        }
    }

    private function registerRateLimitAndDeduplication(ContainerBuilder $container, array $c): void
    {
        $hasRedis = $container->has(\Redis::class);

        // Token bucket — Redis when available (distributed), in-memory fallback
        if ($hasRedis) {
            $container->register(RedisTokenBucket::class, RedisTokenBucket::class)
                ->setArguments([
                    new Reference(\Redis::class),
                    $c['rate_limit']['max_send_rate'],
                    $c['rate_limit']['burst'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias(TokenBucketInterface::class, RedisTokenBucket::class)->setPublic(false);
        } else {
            $container->register(InMemoryTokenBucket::class, InMemoryTokenBucket::class)
                ->setArguments([
                    $c['rate_limit']['max_send_rate'],
                    $c['rate_limit']['burst'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias(TokenBucketInterface::class, InMemoryTokenBucket::class)->setPublic(false);
        }

        // RateLimitMiddleware (priority 550)
        $container->register(RateLimitMiddleware::class, RateLimitMiddleware::class)
            ->setArgument('$tokenBucket',   new Reference(TokenBucketInterface::class))
            ->setArgument('$waitTimeoutMs', $c['rate_limit']['wait_timeout_ms'])
            ->addTag('vortos_aws_ses.email_middleware', ['priority' => 550])
            ->setShared(true)
            ->setPublic(false);

        // Deduplication store — Redis when available (atomic setNx), in-memory fallback
        if ($hasRedis) {
            $container->register(RedisDeduplicationStore::class, RedisDeduplicationStore::class)
                ->setArgument('$cache', new Reference(AtomicCacheInterface::class))
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias(DeduplicationStoreInterface::class, RedisDeduplicationStore::class)->setPublic(false);
        } else {
            $container->register(InMemoryDeduplicationStore::class, InMemoryDeduplicationStore::class)
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias(DeduplicationStoreInterface::class, InMemoryDeduplicationStore::class)->setPublic(false);
        }

        // DeduplicationMiddleware (priority 850)
        $container->register(DeduplicationMiddleware::class, DeduplicationMiddleware::class)
            ->setArgument('$store', new Reference(DeduplicationStoreInterface::class))
            ->addTag('vortos_aws_ses.email_middleware', ['priority' => 850])
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerSuppression(ContainerBuilder $container, array $c): void
    {
        $container->register(DbalSuppressionList::class, DbalSuppressionList::class)
            ->setArgument('$connection', new Reference(\Doctrine\DBAL\Connection::class))
            ->setArgument('$tableName',  $c['suppression']['table_name'])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(SuppressionListInterface::class, DbalSuppressionList::class)->setPublic(false);

        $onSuppressed = OnSuppressed::from($c['suppression']['on_suppressed']);

        $container->register(SuppressionCheckMiddleware::class, SuppressionCheckMiddleware::class)
            ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
            ->setArgument('$onSuppressed',    $onSuppressed)
            ->addTag('vortos_aws_ses.email_middleware', ['priority' => 600])
            ->setShared(true)
            ->setPublic(false);

        if ($c['driver'] === 'ses') {
            $container->register(SuppressionSyncCommand::class, SuppressionSyncCommand::class)
                ->setArgument('$client',          new Reference(SesV2Client::class))
                ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
                ->addTag('console.command')
                ->setShared(true)
                ->setPublic(false);
        }
    }

    private function registerObservability(ContainerBuilder $container, array $c): void
    {
        if ($c['audit_log']['enabled']) {
            $container->register(AuditLogMiddleware::class, AuditLogMiddleware::class)
                ->setArguments([
                    new Reference(\Doctrine\DBAL\Connection::class),
                    new Reference(LoggerInterface::class),
                    $c['audit_log']['table_name'],
                ])
                ->addTag('vortos_aws_ses.email_middleware', ['priority' => 500])
                ->setShared(true)
                ->setPublic(false);
        }

        if ($c['driver'] === 'ses') {
            $container->register(SesQuotaCommand::class, SesQuotaCommand::class)
                ->setArgument('$client', new Reference(SesV2Client::class))
                ->addTag('console.command')
                ->setShared(true)
                ->setPublic(false);
        }

        $container->register(SesSendTestCommand::class, SesSendTestCommand::class)
            ->setArguments([
                new Reference(MailerInterface::class),
                $c['default_from_address'],
            ])
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(SesSuppressionListCommand::class, SesSuppressionListCommand::class)
            ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        // vortos:ses:make:* — only when the Make package's GeneratorEngine is wired
        if ($container->has(GeneratorEngine::class)) {
            foreach ([
                MakeSesEmailMiddlewareCommand::class,
                MakeBounceHandlerCommand::class,
                MakeComplaintHandlerCommand::class,
            ] as $commandClass) {
                $container->register($commandClass, $commandClass)
                    ->setArgument('$engine', new Reference(GeneratorEngine::class))
                    ->addTag('console.command')
                    ->setShared(true)
                    ->setPublic(false);
            }
        }
    }

    private function registerWebhook(ContainerBuilder $container, array $c): void
    {
        if (!$c['webhooks']['enabled']) {
            return;
        }

        // Use cached cert fetcher when a PSR-16 cache is wired (avoids a remote
        // HTTPS round-trip to amazonaws.com on every incoming webhook).
        if ($container->has(CacheInterface::class)) {
            $container->register('vortos_aws_ses.sns_cert_fetcher', \Closure::class)
                ->setFactory([SnsSignatureVerifier::class, 'cachedCertFetcher'])
                ->setArgument(0, new Reference(CacheInterface::class))
                ->setShared(true)
                ->setPublic(false);

            $certFetcherArg = new Reference('vortos_aws_ses.sns_cert_fetcher');
        } else {
            $container->register('vortos_aws_ses.sns_cert_fetcher', \Closure::class)
                ->setFactory([SnsSignatureVerifier::class, 'defaultCertFetcher'])
                ->setShared(true)
                ->setPublic(false);

            $certFetcherArg = new Reference('vortos_aws_ses.sns_cert_fetcher');
        }

        $container->register(SnsSignatureVerifier::class, SnsSignatureVerifier::class)
            ->setArgument('$logger',      new Reference(LoggerInterface::class))
            ->setArgument('$certFetcher', $certFetcherArg)
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(SignatureVerifierInterface::class, SnsSignatureVerifier::class)->setPublic(false);

        $container->register(SnsWebhookController::class, SnsWebhookController::class)
            ->setArguments([
                new Reference(SignatureVerifierInterface::class),
                new Reference(BounceHandlerRunner::class),
                new Reference(ComplaintHandlerRunner::class),
                new Reference(LoggerInterface::class),
            ])
            ->setShared(true)
            ->setPublic(true);
    }

    private function registerFallbackSesClient(ContainerBuilder $container, array $c): void
    {
        $container->register('vortos_aws_ses.fallback_client', SesV2Client::class)
            ->setFactory([SesClientFactory::class, 'create'])
            ->setArguments([
                $c['fallback_region'],
                $c['aws_client']['endpoint_override'],
                $c['aws_client']['http_timeout'],
                $c['aws_client']['max_retries'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register('vortos_aws_ses.fallback_mailer', SesMailer::class)
            ->setArguments([
                new Reference('vortos_aws_ses.fallback_client'),
                $c['fallback_region'],
                $c['default_from_address'],
                $c['default_from_name'],
                $c['reply_to'],
                $c['configuration_set'],
            ])
            ->setShared(true)
            ->setPublic(false);
    }

    private function setParameters(ContainerBuilder $container, array $c): void
    {
        $container->setParameter('vortos_aws_ses.driver',               $c['driver']);
        $container->setParameter('vortos_aws_ses.region',               $c['region']);
        $container->setParameter('vortos_aws_ses.fallback_region',      $c['fallback_region']);
        $container->setParameter('vortos_aws_ses.default_from_address', $c['default_from_address']);
        $container->setParameter('vortos_aws_ses.default_from_name',    $c['default_from_name']);
        $container->setParameter('vortos_aws_ses.reply_to',             $c['reply_to']);
        $container->setParameter('vortos_aws_ses.configuration_set',    $c['configuration_set']);
        $container->setParameter('vortos_aws_ses.template_dir',         $c['template_dir']);

        $container->setParameter('vortos_aws_ses.aws_client.endpoint_override', $c['aws_client']['endpoint_override']);
        $container->setParameter('vortos_aws_ses.aws_client.http_timeout',      $c['aws_client']['http_timeout']);
        $container->setParameter('vortos_aws_ses.aws_client.max_retries',       $c['aws_client']['max_retries']);

        $container->setParameter('vortos_aws_ses.outbox.enabled',                        $c['outbox']['enabled']);
        $container->setParameter('vortos_aws_ses.outbox.table_name',                     $c['outbox']['table_name']);
        $container->setParameter('vortos_aws_ses.outbox.batch_size',                     $c['outbox']['batch_size']);
        $container->setParameter('vortos_aws_ses.outbox.sleep_seconds_when_empty',       $c['outbox']['sleep_seconds_when_empty']);
        $container->setParameter('vortos_aws_ses.outbox.max_delivery_attempts',          $c['outbox']['max_delivery_attempts']);
        $container->setParameter('vortos_aws_ses.outbox.retry_strategy',                 $c['outbox']['retry_strategy']);
        $container->setParameter('vortos_aws_ses.outbox.backoff_base_seconds',           $c['outbox']['backoff_base_seconds']);
        $container->setParameter('vortos_aws_ses.outbox.backoff_cap_seconds',            $c['outbox']['backoff_cap_seconds']);
        $container->setParameter('vortos_aws_ses.outbox.stale_message_timeout_seconds',  $c['outbox']['stale_message_timeout_seconds']);

        $container->setParameter('vortos_aws_ses.webhooks.enabled',    $c['webhooks']['enabled']);
        $container->setParameter('vortos_aws_ses.webhooks.route_path', $c['webhooks']['route_path']);

        $container->setParameter('vortos_aws_ses.suppression.table_name',      $c['suppression']['table_name']);
        $container->setParameter('vortos_aws_ses.suppression.sync_on_startup', $c['suppression']['sync_on_startup']);
        $container->setParameter('vortos_aws_ses.suppression.on_suppressed',   $c['suppression']['on_suppressed']);

        $container->setParameter('vortos_aws_ses.rate_limit.max_send_rate',   $c['rate_limit']['max_send_rate']);
        $container->setParameter('vortos_aws_ses.rate_limit.burst',           $c['rate_limit']['burst']);
        $container->setParameter('vortos_aws_ses.rate_limit.wait_timeout_ms', $c['rate_limit']['wait_timeout_ms']);

        $container->setParameter('vortos_aws_ses.audit_log.enabled',    $c['audit_log']['enabled']);
        $container->setParameter('vortos_aws_ses.audit_log.table_name', $c['audit_log']['table_name']);

        $container->setParameter('vortos_aws_ses.circuit_breaker.failure_threshold',     $c['circuit_breaker']['failure_threshold']);
        $container->setParameter('vortos_aws_ses.circuit_breaker.reset_timeout_seconds', $c['circuit_breaker']['reset_timeout_seconds']);

        $container->setParameter('vortos_aws_ses.observability.logging', $c['observability']['logging']);
        $container->setParameter('vortos_aws_ses.observability.tracing', $c['observability']['tracing']);
        $container->setParameter('vortos_aws_ses.observability.metrics', $c['observability']['metrics']);
        $container->setParameter('vortos_aws_ses.observability.logging_disabled_for', $c['observability']['logging_disabled_for']);
        $container->setParameter('vortos_aws_ses.observability.tracing_disabled_for', $c['observability']['tracing_disabled_for']);
        $container->setParameter('vortos_aws_ses.observability.metrics_disabled_for', $c['observability']['metrics_disabled_for']);
    }
}
