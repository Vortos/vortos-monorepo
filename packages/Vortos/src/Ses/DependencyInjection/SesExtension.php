<?php

declare(strict_types=1);

namespace Vortos\Ses\DependencyInjection;

use Aws\SesV2\SesV2Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Ses\Contract\EmailMiddlewareInterface;
use Vortos\Ses\Contract\MailerInterface;
use Vortos\Ses\Driver\Log\LogMailer;
use Vortos\Ses\Driver\Null\NullMailer;
use Vortos\Ses\Driver\Ses\Health\SesHealthCheck;
use Vortos\Ses\Driver\Ses\SesClientFactory;
use Vortos\Ses\Driver\Ses\SesMailer;
use Vortos\Make\Engine\GeneratorEngine;
use Vortos\Ses\Command\Make\MakeBounceHandlerCommand;
use Vortos\Ses\Command\Make\MakeComplaintHandlerCommand;
use Vortos\Ses\Command\Make\MakeSesEmailMiddlewareCommand;
use Vortos\Ses\Command\SesQuotaCommand;
use Vortos\Ses\Command\SesSendTestCommand;
use Vortos\Ses\Command\SesSuppressionListCommand;
use Vortos\Ses\Failover\CircuitBreaker;
use Vortos\Ses\Middleware\AuditLogMiddleware;
use Vortos\Ses\Failover\MultiRegionMailer;
use Vortos\Ses\Webhook\SignatureVerifierInterface;
use Vortos\Ses\Webhook\SnsSignatureVerifier;
use Vortos\Ses\Webhook\SnsWebhookController;
use Vortos\Ses\Bounce\AutoSuppressionBounceHandler;
use Vortos\Ses\Bounce\AutoSuppressionComplaintHandler;
use Vortos\Ses\Bounce\BounceHandlerRunner;
use Vortos\Ses\Bounce\ComplaintHandlerRunner;
use Vortos\Ses\Command\EmailOutboxRelayCommand;
use Vortos\Ses\Command\SuppressionSyncCommand;
use Vortos\Ses\Contract\BounceHandlerInterface;
use Vortos\Ses\Contract\ComplaintHandlerInterface;
use Vortos\Ses\Contract\EmailOutboxWriterInterface;
use Vortos\Ses\Contract\SuppressionListInterface;
use Vortos\Ses\Contract\TemplateRendererInterface;
use Vortos\Ses\Template\NullTemplateRenderer;
use Vortos\Ses\Template\PhpTemplateRenderer;
use Vortos\Ses\Outbox\EmailOutboxRelay;
use Vortos\Ses\Outbox\EmailOutboxWriter;
use Vortos\Ses\Outbox\OutboxMailer;
use Psr\SimpleCache\CacheInterface;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Ses\Deduplication\DeduplicationStoreInterface;
use Vortos\Ses\Deduplication\InMemoryDeduplicationStore;
use Vortos\Ses\Deduplication\RedisDeduplicationStore;
use Vortos\Ses\Middleware\DeduplicationMiddleware;
use Vortos\Ses\Middleware\EmailMiddlewareStack;
use Vortos\Ses\Middleware\HookMiddleware;
use Vortos\Ses\Middleware\LoggingMiddleware;
use Vortos\Ses\Middleware\RateLimitMiddleware;
use Vortos\Ses\Middleware\SuppressionCheckMiddleware;
use Vortos\Ses\Middleware\TracingMiddleware;
use Vortos\Ses\RateLimit\InMemoryTokenBucket;
use Vortos\Ses\RateLimit\RedisTokenBucket;
use Vortos\Ses\RateLimit\TokenBucketInterface;
use Vortos\Ses\Suppression\DbalSuppressionList;
use Vortos\Ses\Suppression\OnSuppressed;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wires all SES services into the container.
 *
 * Loads config/ses.php then config/{env}/ses.php (env file overrides base).
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
final class SesExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_ses';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $vortosConfig = new VortosSesConfig();

        $base = $projectDir . '/config/ses.php';
        if (file_exists($base)) {
            (require $base)($vortosConfig);
        }

        $envFile = $projectDir . '/config/' . $env . '/ses.php';
        if (file_exists($envFile)) {
            (require $envFile)($vortosConfig);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$vortosConfig->toArray()]);

        $this->setParameters($container, $resolved);
        $this->registerDrivers($container, $resolved);
        $this->registerMiddlewareStack($container);
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
        // NullMailer — always registered (safe to inject by class in tests)
        $container->register(NullMailer::class, NullMailer::class)
            ->setShared(true)
            ->setPublic(false);

        // LogMailer — always registered
        $container->register(LogMailer::class, LogMailer::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setShared(true)
            ->setPublic(false);

        // SesMailer + SesV2Client — only when driver=ses
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

            // Also register a fallback SesV2Client for the fallback region if configured
            if ($c['fallback_region'] !== null) {
                $this->registerFallbackSesClient($container, $c);
            }
        }

        // vortos_ses.driver — the raw transport (not wrapped in middleware stack)
        if ($c['driver'] === 'ses' && $c['fallback_region'] !== null) {
            // Multi-region: wrap primary + fallback in circuit-breaking failover mailer
            $container->register('vortos_ses.primary_circuit_breaker', CircuitBreaker::class)
                ->setArguments([
                    $c['circuit_breaker']['failure_threshold'],
                    $c['circuit_breaker']['reset_timeout_seconds'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register('vortos_ses.fallback_circuit_breaker', CircuitBreaker::class)
                ->setArguments([
                    $c['circuit_breaker']['failure_threshold'],
                    $c['circuit_breaker']['reset_timeout_seconds'],
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->register(MultiRegionMailer::class, MultiRegionMailer::class)
                ->setArguments([
                    new Reference(SesMailer::class),
                    new Reference('vortos_ses.fallback_mailer'),
                    new Reference('vortos_ses.primary_circuit_breaker'),
                    new Reference('vortos_ses.fallback_circuit_breaker'),
                    new Reference(LoggerInterface::class),
                ])
                ->setShared(true)
                ->setPublic(false);

            $container->setAlias('vortos_ses.driver', MultiRegionMailer::class)->setPublic(false);
        } else {
            $driverClass = match ($c['driver']) {
                'ses'   => SesMailer::class,
                'log'   => LogMailer::class,
                default => NullMailer::class,
            };

            $container->setAlias('vortos_ses.driver', $driverClass)->setPublic(false);
        }
    }

    private function registerMiddlewareStack(ContainerBuilder $container): void
    {
        // Autoconfigure: any EmailMiddlewareInterface gets the email_middleware tag automatically
        $container->registerForAutoconfiguration(EmailMiddlewareInterface::class)
            ->addTag('vortos_ses.email_middleware');

        // Built-in: LoggingMiddleware (priority 900)
        $container->register(LoggingMiddleware::class, LoggingMiddleware::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->addTag('vortos_ses.email_middleware', ['priority' => 900])
            ->setShared(true)
            ->setPublic(false);

        // Built-in: TracingMiddleware (priority 800)
        $container->register(TracingMiddleware::class, TracingMiddleware::class)
            ->setArgument('$tracer', new Reference(TracingInterface::class))
            ->addTag('vortos_ses.email_middleware', ['priority' => 800])
            ->setShared(true)
            ->setPublic(false);

        // Built-in: HookMiddleware (priority 700) — observers injected by MiddlewareCompilerPass
        $container->register(HookMiddleware::class, HookMiddleware::class)
            ->setArgument('$observers', [])
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->addTag('vortos_ses.email_middleware', ['priority' => 700])
            ->setShared(true)
            ->setPublic(false);

        // EmailMiddlewareStack wraps all middleware around the raw driver
        // MiddlewareCompilerPass populates $middlewares in priority order
        $container->register(EmailMiddlewareStack::class, EmailMiddlewareStack::class)
            ->setArgument('$driver', new Reference('vortos_ses.driver'))
            ->setArgument('$middlewares', [])
            ->setShared(true)
            ->setPublic(false);

        // vortos_ses.sending_mailer — the real network-bound stack.
        // EmailOutboxRelay injects this directly to avoid recursion when
        // MailerInterface is redirected through OutboxMailer.
        $container->setAlias('vortos_ses.sending_mailer', EmailMiddlewareStack::class)->setPublic(false);
    }

    private function registerBounceAndComplaint(ContainerBuilder $container): void
    {
        // Autoconfigure
        $container->registerForAutoconfiguration(BounceHandlerInterface::class)
            ->addTag('vortos_ses.bounce_handler');
        $container->registerForAutoconfiguration(ComplaintHandlerInterface::class)
            ->addTag('vortos_ses.complaint_handler');

        // Auto-suppression handlers (always registered — needed by the runners)
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
            // Outbox disabled: MailerInterface → sending stack directly (synchronous)
            $container->setAlias(MailerInterface::class, EmailMiddlewareStack::class)->setPublic(false);
            return;
        }

        $container->register(EmailOutboxWriter::class, EmailOutboxWriter::class)
            ->setArgument('$connection', new Reference(\Doctrine\DBAL\Connection::class))
            ->setArgument('$tableName',  $c['outbox']['table_name'])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(EmailOutboxWriterInterface::class, EmailOutboxWriter::class)->setPublic(false);

        // OutboxMailer: MailerInterface::send() writes to DB and returns immediately.
        $container->register(OutboxMailer::class, OutboxMailer::class)
            ->setArgument('$writer', new Reference(EmailOutboxWriterInterface::class))
            ->setShared(true)
            ->setPublic(false);

        // Outbox enabled: MailerInterface → OutboxMailer (pit of success)
        $container->setAlias(MailerInterface::class, OutboxMailer::class)->setPublic(false);

        // Relay injects vortos_ses.sending_mailer (EmailMiddlewareStack) directly —
        // NOT MailerInterface — to avoid writing back into the outbox in a loop.
        $container->register(EmailOutboxRelay::class, EmailOutboxRelay::class)
            ->setArguments([
                new Reference(\Doctrine\DBAL\Connection::class),
                new Reference('vortos_ses.sending_mailer'),
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
            ->addTag('vortos_ses.email_middleware', ['priority' => 550])
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
            ->addTag('vortos_ses.email_middleware', ['priority' => 850])
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerSuppression(ContainerBuilder $container, array $c): void
    {
        // DbalSuppressionList — always registered; needs DBAL Connection
        $container->register(DbalSuppressionList::class, DbalSuppressionList::class)
            ->setArgument('$connection', new Reference(\Doctrine\DBAL\Connection::class))
            ->setArgument('$tableName',  $c['suppression']['table_name'])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(SuppressionListInterface::class, DbalSuppressionList::class)->setPublic(false);

        // SuppressionCheckMiddleware — wired with config-driven OnSuppressed enum value
        $onSuppressed = OnSuppressed::from($c['suppression']['on_suppressed']);

        $container->register(SuppressionCheckMiddleware::class, SuppressionCheckMiddleware::class)
            ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
            ->setArgument('$onSuppressed',    $onSuppressed)
            ->addTag('vortos_ses.email_middleware', ['priority' => 600])
            ->setShared(true)
            ->setPublic(false);

        // SuppressionSyncCommand — only registered when driver=ses (needs SesV2Client)
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
        // AuditLogMiddleware — only when audit_log.enabled=true
        if ($c['audit_log']['enabled']) {
            $container->register(AuditLogMiddleware::class, AuditLogMiddleware::class)
                ->setArguments([
                    new Reference(\Doctrine\DBAL\Connection::class),
                    new Reference(LoggerInterface::class),
                    $c['audit_log']['table_name'],
                ])
                ->addTag('vortos_ses.email_middleware', ['priority' => 500])
                ->setShared(true)
                ->setPublic(false);
        }

        // ses:quota — only when driver=ses (needs SesV2Client)
        if ($c['driver'] === 'ses') {
            $container->register(SesQuotaCommand::class, SesQuotaCommand::class)
                ->setArgument('$client', new Reference(SesV2Client::class))
                ->addTag('console.command')
                ->setShared(true)
                ->setPublic(false);
        }

        // ses:send:test — always available (uses active MailerInterface)
        $container->register(SesSendTestCommand::class, SesSendTestCommand::class)
            ->setArguments([
                new Reference(MailerInterface::class),
                $c['default_from_address'],
            ])
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        // ses:suppression:list — always available
        $container->register(SesSuppressionListCommand::class, SesSuppressionListCommand::class)
            ->setArgument('$suppressionList', new Reference(SuppressionListInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        // ses:make:* — only when the Make package's GeneratorEngine is wired
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
            $container->register('vortos_ses.sns_cert_fetcher', \Closure::class)
                ->setFactory([SnsSignatureVerifier::class, 'cachedCertFetcher'])
                ->setArgument(0, new Reference(CacheInterface::class))
                ->setShared(true)
                ->setPublic(false);

            $certFetcherArg = new Reference('vortos_ses.sns_cert_fetcher');
        } else {
            $certFetcherArg = SnsSignatureVerifier::defaultCertFetcher();
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
        $container->register('vortos_ses.fallback_client', SesV2Client::class)
            ->setFactory([SesClientFactory::class, 'create'])
            ->setArguments([
                $c['fallback_region'],
                $c['aws_client']['endpoint_override'],
                $c['aws_client']['http_timeout'],
                $c['aws_client']['max_retries'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register('vortos_ses.fallback_mailer', SesMailer::class)
            ->setArguments([
                new Reference('vortos_ses.fallback_client'),
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
        $container->setParameter('vortos_ses.driver',               $c['driver']);
        $container->setParameter('vortos_ses.region',               $c['region']);
        $container->setParameter('vortos_ses.fallback_region',      $c['fallback_region']);
        $container->setParameter('vortos_ses.default_from_address', $c['default_from_address']);
        $container->setParameter('vortos_ses.default_from_name',    $c['default_from_name']);
        $container->setParameter('vortos_ses.reply_to',             $c['reply_to']);
        $container->setParameter('vortos_ses.configuration_set',    $c['configuration_set']);
        $container->setParameter('vortos_ses.template_dir',         $c['template_dir']);

        $container->setParameter('vortos_ses.aws_client.endpoint_override', $c['aws_client']['endpoint_override']);
        $container->setParameter('vortos_ses.aws_client.http_timeout',      $c['aws_client']['http_timeout']);
        $container->setParameter('vortos_ses.aws_client.max_retries',       $c['aws_client']['max_retries']);

        $container->setParameter('vortos_ses.outbox.enabled',                        $c['outbox']['enabled']);
        $container->setParameter('vortos_ses.outbox.table_name',                     $c['outbox']['table_name']);
        $container->setParameter('vortos_ses.outbox.batch_size',                     $c['outbox']['batch_size']);
        $container->setParameter('vortos_ses.outbox.sleep_seconds_when_empty',       $c['outbox']['sleep_seconds_when_empty']);
        $container->setParameter('vortos_ses.outbox.max_delivery_attempts',          $c['outbox']['max_delivery_attempts']);
        $container->setParameter('vortos_ses.outbox.retry_strategy',                 $c['outbox']['retry_strategy']);
        $container->setParameter('vortos_ses.outbox.backoff_base_seconds',           $c['outbox']['backoff_base_seconds']);
        $container->setParameter('vortos_ses.outbox.backoff_cap_seconds',            $c['outbox']['backoff_cap_seconds']);
        $container->setParameter('vortos_ses.outbox.stale_message_timeout_seconds',  $c['outbox']['stale_message_timeout_seconds']);

        $container->setParameter('vortos_ses.webhooks.enabled',    $c['webhooks']['enabled']);
        $container->setParameter('vortos_ses.webhooks.route_path', $c['webhooks']['route_path']);

        $container->setParameter('vortos_ses.suppression.table_name',      $c['suppression']['table_name']);
        $container->setParameter('vortos_ses.suppression.sync_on_startup', $c['suppression']['sync_on_startup']);
        $container->setParameter('vortos_ses.suppression.on_suppressed',   $c['suppression']['on_suppressed']);

        $container->setParameter('vortos_ses.rate_limit.max_send_rate',   $c['rate_limit']['max_send_rate']);
        $container->setParameter('vortos_ses.rate_limit.burst',           $c['rate_limit']['burst']);
        $container->setParameter('vortos_ses.rate_limit.wait_timeout_ms', $c['rate_limit']['wait_timeout_ms']);

        $container->setParameter('vortos_ses.audit_log.enabled',    $c['audit_log']['enabled']);
        $container->setParameter('vortos_ses.audit_log.table_name', $c['audit_log']['table_name']);

        $container->setParameter('vortos_ses.circuit_breaker.failure_threshold',     $c['circuit_breaker']['failure_threshold']);
        $container->setParameter('vortos_ses.circuit_breaker.reset_timeout_seconds', $c['circuit_breaker']['reset_timeout_seconds']);
    }
}
