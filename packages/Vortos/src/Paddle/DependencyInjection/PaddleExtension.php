<?php

declare(strict_types=1);

namespace Vortos\Paddle\DependencyInjection;

use Doctrine\DBAL\Connection;
use Paddle\SDK\Client as PaddleSdkClient;
use Paddle\SDK\Environment as PaddleEnvironment;
use Paddle\SDK\Options as PaddleOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Paddle\Api\ApiIdempotencyStore;
use Vortos\Paddle\Api\PaddleApiClient;
use Vortos\Paddle\Api\PaddleSdkExceptionMapper;
use Vortos\Paddle\AuditLog\PaddleAuditLogWriter;
use Vortos\Paddle\AuditLog\PaddleAuditLogWriterInterface;
use Vortos\Paddle\Command\PaddleWebhookIdempotencyPruneCommand;
use Vortos\Paddle\Failover\PaddleCircuitBreaker;
use Vortos\Paddle\Outbox\PaddleOutboxWriter;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Webhook\PaddleWebhookController;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface;
use Vortos\Paddle\Webhook\WebhookEventFactory;
use Vortos\Paddle\Webhook\WebhookIdempotencyStore;
use Vortos\Paddle\Webhook\WebhookIpGuard;
use Vortos\Paddle\Webhook\WebhookVerifier;
use Vortos\Paddle\Webhook\WebhookVerifierInterface;

final class PaddleExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_paddle';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $config = new VortosPaddleConfig();

        $base = $projectDir . '/config/paddle.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/paddle.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $this->setParameters($container, $resolved);
        $this->registerApiClient($container, $resolved, $prefix);
        $this->registerWebhooks($container, $resolved, $prefix);
        $this->registerOutboxAndAuditLog($container, $prefix);
    }

    private function registerApiClient(ContainerBuilder $container, array $config, string $prefix): void
    {
        $environment = $config['mode'] === 'live'
            ? PaddleEnvironment::PRODUCTION
            : PaddleEnvironment::SANDBOX;

        $container->register(PaddleOptions::class, PaddleOptions::class)
            ->setArguments([$environment, $config['client']['max_retries']])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleSdkClient::class, PaddleSdkClient::class)
            ->setArguments([
                $config['api_key'],
                new Reference(PaddleOptions::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleCircuitBreaker::class, PaddleCircuitBreaker::class)
            ->setArguments([
                '$failureThreshold'    => $config['circuit_breaker']['failure_threshold'],
                '$resetTimeoutSeconds' => $config['circuit_breaker']['reset_timeout_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleSdkExceptionMapper::class, PaddleSdkExceptionMapper::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleApiClient::class, PaddleApiClient::class)
            ->setArguments([
                '$sdk'               => new Reference(PaddleSdkClient::class),
                '$circuitBreaker'    => new Reference(PaddleCircuitBreaker::class),
                '$exceptionMapper'   => new Reference(PaddleSdkExceptionMapper::class),
                '$maxRetries'        => $config['client']['max_retries'],
                '$retryOnRateLimit'  => $config['client']['retry_on_rate_limit'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(ApiIdempotencyStore::class, ApiIdempotencyStore::class)
            ->setArguments([
                '$connection' => new Reference(Connection::class),
                '$tableName'  => $prefix . 'paddle_idempotency_keys',
                '$ttlSeconds' => $config['client']['idempotency_key_ttl_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerWebhooks(ContainerBuilder $container, array $config, string $prefix): void
    {
        if (!$config['webhooks']['enabled']) {
            return;
        }

        $container->registerForAutoconfiguration(PaddleWebhookHandlerInterface::class)
            ->addTag('vortos_paddle.webhook_handler');

        $container->register(WebhookVerifier::class, WebhookVerifier::class)
            ->setArguments([
                '$notificationSecrets' => $config['notification_secret'],
                '$replayWindowSeconds' => $config['security']['replay_window_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(WebhookVerifierInterface::class, WebhookVerifier::class)->setPublic(false);

        $container->register(WebhookIpGuard::class, WebhookIpGuard::class)
            ->setArguments([
                '$enabled'        => $config['security']['enforce_ip_allowlist'],
                '$allowSandboxIps' => $config['security']['allow_sandbox_ips'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(WebhookEventFactory::class, WebhookEventFactory::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(WebhookIdempotencyStore::class, WebhookIdempotencyStore::class)
            ->setArguments([
                '$connection' => new Reference(Connection::class),
                '$tableName'  => $prefix . $config['webhooks']['idempotency_table'],
                '$ttlSeconds' => $config['webhooks']['idempotency_ttl_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleWebhookDispatcher::class, PaddleWebhookDispatcher::class)
            ->setArguments([
                '$handlers' => [],
                '$logger'   => new Reference(LoggerInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleWebhookController::class, PaddleWebhookController::class)
            ->setArguments([
                '$verifier'          => new Reference(WebhookVerifierInterface::class),
                '$ipGuard'           => new Reference(WebhookIpGuard::class),
                '$idempotencyStore'  => new Reference(WebhookIdempotencyStore::class),
                '$eventFactory'      => new Reference(WebhookEventFactory::class),
                '$dispatcher'        => new Reference(PaddleWebhookDispatcher::class),
                '$logger'            => new Reference(LoggerInterface::class),
                '$webhookPath'       => $config['webhook_path'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleWebhookIdempotencyPruneCommand::class, PaddleWebhookIdempotencyPruneCommand::class)
            ->setArguments([
                '$store' => new Reference(WebhookIdempotencyStore::class),
            ])
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerOutboxAndAuditLog(ContainerBuilder $container, string $prefix): void
    {
        $container->register(PaddleOutboxWriter::class, PaddleOutboxWriter::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'paddle_outbox')
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PaddleOutboxWriterInterface::class, PaddleOutboxWriter::class)
            ->setPublic(false);

        $container->register(PaddleAuditLogWriter::class, PaddleAuditLogWriter::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'paddle_audit_log')
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PaddleAuditLogWriterInterface::class, PaddleAuditLogWriter::class)
            ->setPublic(false);
    }

    private function setParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('vortos_paddle.mode',               $config['mode']);
        $container->setParameter('vortos_paddle.webhook_path',       $config['webhook_path']);
        $container->setParameter('vortos_paddle.client.max_retries',               $config['client']['max_retries']);
        $container->setParameter('vortos_paddle.client.retry_on_rate_limit',       $config['client']['retry_on_rate_limit']);
        $container->setParameter('vortos_paddle.client.idempotency_key_ttl_seconds', $config['client']['idempotency_key_ttl_seconds']);
        $container->setParameter('vortos_paddle.circuit_breaker.enabled',               $config['circuit_breaker']['enabled']);
        $container->setParameter('vortos_paddle.circuit_breaker.failure_threshold',     $config['circuit_breaker']['failure_threshold']);
        $container->setParameter('vortos_paddle.circuit_breaker.reset_timeout_seconds', $config['circuit_breaker']['reset_timeout_seconds']);
        $container->setParameter('vortos_paddle.webhooks.enabled',   $config['webhooks']['enabled']);
        $container->setParameter('vortos_paddle.webhooks.idempotency_table',       $config['webhooks']['idempotency_table']);
        $container->setParameter('vortos_paddle.webhooks.idempotency_ttl_seconds', $config['webhooks']['idempotency_ttl_seconds']);
        $container->setParameter('vortos_paddle.security.enforce_ip_allowlist',  $config['security']['enforce_ip_allowlist']);
        $container->setParameter('vortos_paddle.security.replay_window_seconds', $config['security']['replay_window_seconds']);
        $container->setParameter('vortos_paddle.security.allow_sandbox_ips',     $config['security']['allow_sandbox_ips']);
        $container->setParameter('vortos_paddle.observability.logging', $config['observability']['logging']);
        $container->setParameter('vortos_paddle.observability.tracing', $config['observability']['tracing']);
        $container->setParameter('vortos_paddle.observability.metrics', $config['observability']['metrics']);
    }
}
