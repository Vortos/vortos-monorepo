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
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Api\PaddleSdkExceptionMapper;
use Vortos\Paddle\AuditLog\PaddleAuditLogWriter;
use Vortos\Paddle\AuditLog\PaddleAuditLogWriterInterface;
use Vortos\Paddle\Catalog\Contract\DiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateDiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediatePriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Catalog\Contract\PricePreviewServiceInterface;
use Vortos\Paddle\Catalog\Contract\PriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\ProductServiceInterface;
use Vortos\Paddle\Catalog\Contract\StandaloneDiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\StandalonePriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\StandaloneProductServiceInterface;
use Vortos\Paddle\Catalog\ImmediateDiscountService;
use Vortos\Paddle\Catalog\ImmediatePriceService;
use Vortos\Paddle\Catalog\ImmediateProductService;
use Vortos\Paddle\Catalog\PricePreviewService;
use Vortos\Paddle\Catalog\StandaloneDiscountService;
use Vortos\Paddle\Catalog\StandalonePriceService;
use Vortos\Paddle\Catalog\StandaloneProductService;
use Vortos\Paddle\Catalog\TransactionalDiscountService;
use Vortos\Paddle\Catalog\TransactionalPriceService;
use Vortos\Paddle\Catalog\TransactionalProductService;
use Vortos\Paddle\Checkout\CheckoutService;
use Vortos\Paddle\Checkout\PortalSessionService;
use Vortos\Paddle\Command\PaddleOutboxRelayCommand;
use Vortos\Paddle\Command\PaddleWebhookIdempotencyPruneCommand;
use Vortos\Paddle\Customer\Contract\AddressServiceInterface;
use Vortos\Paddle\Customer\Contract\BusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\CustomerServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateAddressServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateBusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Contract\StandaloneAddressServiceInterface;
use Vortos\Paddle\Customer\Contract\StandaloneBusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\StandaloneCustomerServiceInterface;
use Vortos\Paddle\Customer\ImmediateAddressService;
use Vortos\Paddle\Customer\ImmediateBusinessService;
use Vortos\Paddle\Customer\ImmediateCustomerService;
use Vortos\Paddle\Customer\StandaloneAddressService;
use Vortos\Paddle\Customer\StandaloneBusinessService;
use Vortos\Paddle\Customer\StandaloneCustomerService;
use Vortos\Paddle\Customer\TransactionalAddressService;
use Vortos\Paddle\Customer\TransactionalBusinessService;
use Vortos\Paddle\Customer\TransactionalCustomerService;
use Vortos\Paddle\Failover\PaddleCircuitBreaker;
use Vortos\Paddle\Outbox\PaddleApiOutboxDispatcher;
use Vortos\Paddle\Outbox\PaddleOutboxDispatcherInterface;
use Vortos\Paddle\Outbox\PaddleOutboxRelay;
use Vortos\Paddle\Outbox\PaddleOutboxWriter;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\Report\EventLogService;
use Vortos\Paddle\Report\ReportService;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Contract\StandaloneSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Contract\SubscriptionServiceInterface;
use Vortos\Paddle\Subscription\ImmediateSubscriptionService;
use Vortos\Paddle\Subscription\StandaloneSubscriptionService;
use Vortos\Paddle\Subscription\TransactionalSubscriptionService;
use Vortos\Paddle\Transaction\Contract\AdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Contract\StandaloneAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\StandaloneTransactionServiceInterface;
use Vortos\Paddle\Transaction\Contract\TransactionServiceInterface;
use Vortos\Paddle\Transaction\ImmediateAdjustmentService;
use Vortos\Paddle\Transaction\ImmediateTransactionService;
use Vortos\Paddle\Transaction\StandaloneAdjustmentService;
use Vortos\Paddle\Transaction\StandaloneTransactionService;
use Vortos\Paddle\Transaction\TransactionalAdjustmentService;
use Vortos\Paddle\Transaction\TransactionalTransactionService;
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
        $this->registerDomainServices($container);
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

        $container->setAlias(PaddleApiClientInterface::class, PaddleApiClient::class)
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

        $allowSandboxIps = $config['security']['allow_sandbox_ips'] || $config['mode'] === 'sandbox';

        $container->register(WebhookIpGuard::class, WebhookIpGuard::class)
            ->setArguments([
                '$enabled'         => $config['security']['enforce_ip_allowlist'],
                '$allowSandboxIps' => $allowSandboxIps,
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
                '$verifier'         => new Reference(WebhookVerifierInterface::class),
                '$ipGuard'          => new Reference(WebhookIpGuard::class),
                '$idempotencyStore' => new Reference(WebhookIdempotencyStore::class),
                '$eventFactory'     => new Reference(WebhookEventFactory::class),
                '$dispatcher'       => new Reference(PaddleWebhookDispatcher::class),
                '$logger'           => new Reference(LoggerInterface::class),
                '$webhookPath'      => $config['webhook_path'],
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

        $container->register(PaddleApiOutboxDispatcher::class, PaddleApiOutboxDispatcher::class)
            ->setArguments([
                '$customers'     => new Reference(ImmediateCustomerServiceInterface::class),
                '$addresses'     => new Reference(ImmediateAddressServiceInterface::class),
                '$businesses'    => new Reference(ImmediateBusinessServiceInterface::class),
                '$transactions'  => new Reference(ImmediateTransactionServiceInterface::class),
                '$adjustments'   => new Reference(ImmediateAdjustmentServiceInterface::class),
                '$products'      => new Reference(ImmediateProductServiceInterface::class),
                '$prices'        => new Reference(ImmediatePriceServiceInterface::class),
                '$discounts'     => new Reference(ImmediateDiscountServiceInterface::class),
                '$subscriptions' => new Reference(ImmediateSubscriptionServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PaddleOutboxDispatcherInterface::class, PaddleApiOutboxDispatcher::class)
            ->setPublic(false);

        $container->register(PaddleOutboxRelay::class, PaddleOutboxRelay::class)
            ->setArguments([
                '$connection'  => new Reference(Connection::class),
                '$dispatcher'  => new Reference(PaddleOutboxDispatcherInterface::class),
                '$logger'      => new Reference(LoggerInterface::class),
                '$table'       => $prefix . 'paddle_outbox',
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PaddleOutboxRelayCommand::class, PaddleOutboxRelayCommand::class)
            ->setArgument('$relay', new Reference(PaddleOutboxRelay::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerDomainServices(ContainerBuilder $container): void
    {
        $this->registerCustomerServices($container);
        $this->registerTransactionServices($container);
        $this->registerCatalogServices($container);
        $this->registerSubscriptionServices($container);
        $this->registerCheckoutServices($container);
        $this->registerReportServices($container);
    }

    private function registerCustomerServices(ContainerBuilder $container): void
    {
        // Customer
        $container->register(ImmediateCustomerService::class, ImmediateCustomerService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateCustomerServiceInterface::class, ImmediateCustomerService::class)
            ->setPublic(false);

        $container->register(TransactionalCustomerService::class, TransactionalCustomerService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateCustomerServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(CustomerServiceInterface::class, TransactionalCustomerService::class)
            ->setPublic(false);

        $container->register(StandaloneCustomerService::class, StandaloneCustomerService::class)
            ->setArguments([
                '$connection'   => new Reference(Connection::class),
                '$transactional' => new Reference(CustomerServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneCustomerServiceInterface::class, StandaloneCustomerService::class)
            ->setPublic(false);

        // Address
        $container->register(ImmediateAddressService::class, ImmediateAddressService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateAddressServiceInterface::class, ImmediateAddressService::class)
            ->setPublic(false);

        $container->register(TransactionalAddressService::class, TransactionalAddressService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateAddressServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(AddressServiceInterface::class, TransactionalAddressService::class)
            ->setPublic(false);

        $container->register(StandaloneAddressService::class, StandaloneAddressService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(AddressServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneAddressServiceInterface::class, StandaloneAddressService::class)
            ->setPublic(false);

        // Business
        $container->register(ImmediateBusinessService::class, ImmediateBusinessService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateBusinessServiceInterface::class, ImmediateBusinessService::class)
            ->setPublic(false);

        $container->register(TransactionalBusinessService::class, TransactionalBusinessService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateBusinessServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(BusinessServiceInterface::class, TransactionalBusinessService::class)
            ->setPublic(false);

        $container->register(StandaloneBusinessService::class, StandaloneBusinessService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(BusinessServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneBusinessServiceInterface::class, StandaloneBusinessService::class)
            ->setPublic(false);
    }

    private function registerTransactionServices(ContainerBuilder $container): void
    {
        // Transaction
        $container->register(ImmediateTransactionService::class, ImmediateTransactionService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateTransactionServiceInterface::class, ImmediateTransactionService::class)
            ->setPublic(false);

        $container->register(TransactionalTransactionService::class, TransactionalTransactionService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateTransactionServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(TransactionServiceInterface::class, TransactionalTransactionService::class)
            ->setPublic(false);

        $container->register(StandaloneTransactionService::class, StandaloneTransactionService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(TransactionServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneTransactionServiceInterface::class, StandaloneTransactionService::class)
            ->setPublic(false);

        // Adjustment
        $container->register(ImmediateAdjustmentService::class, ImmediateAdjustmentService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateAdjustmentServiceInterface::class, ImmediateAdjustmentService::class)
            ->setPublic(false);

        $container->register(TransactionalAdjustmentService::class, TransactionalAdjustmentService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateAdjustmentServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(AdjustmentServiceInterface::class, TransactionalAdjustmentService::class)
            ->setPublic(false);

        $container->register(StandaloneAdjustmentService::class, StandaloneAdjustmentService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(AdjustmentServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneAdjustmentServiceInterface::class, StandaloneAdjustmentService::class)
            ->setPublic(false);
    }

    private function registerCatalogServices(ContainerBuilder $container): void
    {
        // Product
        $container->register(ImmediateProductService::class, ImmediateProductService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateProductServiceInterface::class, ImmediateProductService::class)
            ->setPublic(false);

        $container->register(TransactionalProductService::class, TransactionalProductService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateProductServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ProductServiceInterface::class, TransactionalProductService::class)
            ->setPublic(false);

        $container->register(StandaloneProductService::class, StandaloneProductService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(ProductServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneProductServiceInterface::class, StandaloneProductService::class)
            ->setPublic(false);

        // Price
        $container->register(ImmediatePriceService::class, ImmediatePriceService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediatePriceServiceInterface::class, ImmediatePriceService::class)
            ->setPublic(false);

        $container->register(TransactionalPriceService::class, TransactionalPriceService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediatePriceServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(PriceServiceInterface::class, TransactionalPriceService::class)
            ->setPublic(false);

        $container->register(StandalonePriceService::class, StandalonePriceService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(PriceServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandalonePriceServiceInterface::class, StandalonePriceService::class)
            ->setPublic(false);

        // Discount
        $container->register(ImmediateDiscountService::class, ImmediateDiscountService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateDiscountServiceInterface::class, ImmediateDiscountService::class)
            ->setPublic(false);

        $container->register(TransactionalDiscountService::class, TransactionalDiscountService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateDiscountServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(DiscountServiceInterface::class, TransactionalDiscountService::class)
            ->setPublic(false);

        $container->register(StandaloneDiscountService::class, StandaloneDiscountService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(DiscountServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneDiscountServiceInterface::class, StandaloneDiscountService::class)
            ->setPublic(false);

        // Price Preview (read-only, no transactional tier)
        $container->register(PricePreviewService::class, PricePreviewService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(PricePreviewServiceInterface::class, PricePreviewService::class)
            ->setPublic(false);
    }

    private function registerSubscriptionServices(ContainerBuilder $container): void
    {
        $container->register(ImmediateSubscriptionService::class, ImmediateSubscriptionService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(ImmediateSubscriptionServiceInterface::class, ImmediateSubscriptionService::class)
            ->setPublic(false);

        $container->register(TransactionalSubscriptionService::class, TransactionalSubscriptionService::class)
            ->setArguments([
                '$outbox'  => new Reference(PaddleOutboxWriterInterface::class),
                '$reader'  => new Reference(ImmediateSubscriptionServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(SubscriptionServiceInterface::class, TransactionalSubscriptionService::class)
            ->setPublic(false);

        $container->register(StandaloneSubscriptionService::class, StandaloneSubscriptionService::class)
            ->setArguments([
                '$connection'    => new Reference(Connection::class),
                '$transactional' => new Reference(SubscriptionServiceInterface::class),
            ])
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(StandaloneSubscriptionServiceInterface::class, StandaloneSubscriptionService::class)
            ->setPublic(false);
    }

    private function registerCheckoutServices(ContainerBuilder $container): void
    {
        $container->register(CheckoutService::class, CheckoutService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(PortalSessionService::class, PortalSessionService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);
    }

    private function registerReportServices(ContainerBuilder $container): void
    {
        $container->register(ReportService::class, ReportService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(EventLogService::class, EventLogService::class)
            ->setArgument('$client', new Reference(PaddleApiClientInterface::class))
            ->setShared(true)
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
