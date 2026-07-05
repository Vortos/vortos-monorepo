<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Jwt\Key\Keyring;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Contract\RehashableUserPersisterInterface;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Listener\PasswordRehashListener;
use Vortos\Auth\Hasher\ArgonPasswordHasher;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Jwt\Jwks\JwksController;
use Vortos\Auth\Jwt\Jwks\JwksExporter;
use Vortos\Auth\Lockout\CircuitBreaker\LockoutCircuitBreaker;
use Vortos\Auth\Lockout\LockoutFailureMode;
use Vortos\Auth\Lockout\LockoutKeyNormalizer;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Lockout\Storage\RedisLockoutStore;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Auth\Quota\Contract\QuotaStoreInterface;
use Vortos\Auth\Quota\Middleware\QuotaMiddleware;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Quota\Resolver\GlobalQuotaResolver;
use Vortos\Auth\Quota\Resolver\UserQuotaResolver;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;
use Vortos\Auth\RateLimit\CircuitBreaker\RateLimitCircuitBreaker;
use Vortos\Auth\RateLimit\Middleware\RateLimitMiddleware;
use Vortos\Auth\RateLimit\RateLimitFailureConfig;
use Vortos\Auth\RateLimit\RateLimitFailureMode;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;
use Vortos\Auth\RateLimit\Storage\ResilientRateLimitStore;
use Vortos\Auth\FeatureAccess\Middleware\FeatureAccessMiddleware;
use Vortos\Auth\Audit\AuditFailureMode;
use Vortos\Auth\Audit\Contract\AuditChainReaderInterface;
use Vortos\Auth\Audit\Integrity\AuthAuditChainVerifier;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;
use Vortos\Auth\Audit\Integrity\ChainStateStoreInterface;
use Vortos\Auth\Audit\Integrity\InMemoryChainStateStore;
use Vortos\Auth\Audit\Integrity\RedisChainStateStore;
use Vortos\Auth\Audit\Middleware\AuditMiddleware;
use Vortos\Auth\Command\KeysGenerateCommand;
use Vortos\Auth\Command\KeysListCommand;
use Vortos\Auth\Command\RevokeAllTokensCommand;
use Vortos\Auth\Command\VerifyAuditChainCommand;
use Vortos\Auth\Contract\TokenFreshnessGuardInterface;
use Vortos\Auth\TokenFreshness\CompositeTokenFreshnessGuard;
use Vortos\Auth\TokenFreshness\MinIatGuard;
use Vortos\Auth\TokenFreshness\MinIatStoreInterface;
use Vortos\Auth\TokenFreshness\Storage\InMemoryMinIatStore;
use Vortos\Auth\TokenFreshness\Storage\RedisMinIatStore;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;
use Vortos\Auth\Session\Compiler\SessionCompilerPass;
use Vortos\Auth\Session\Contract\SessionStoreInterface;
use Vortos\Auth\Session\SessionEnforcer;
use Vortos\Auth\Session\Storage\RedisSessionStore;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Auth\Storage\RedisTokenStorage;
use Vortos\Auth\ApiKey\ApiKeyService;
use Vortos\Auth\ApiKey\Middleware\ApiKeyAuthMiddleware;
use Vortos\Auth\ApiKey\Storage\ApiKeyStorageInterface;
use Vortos\Auth\ApiKey\Storage\RedisApiKeyStorage;
use Vortos\Auth\Scim\Http\ScimController;
use Vortos\Auth\Scim\Http\ScimDiscoveryController;
use Vortos\Auth\Scim\Middleware\ScimAuthMiddleware;
use Vortos\Auth\Scim\ScimAuditLogger;
use Vortos\Auth\Scim\ScimRoleGuard;
use Vortos\Auth\Scim\ScimService;
use Vortos\Auth\Scim\Storage\InMemoryScimGroupStorage;
use Vortos\Auth\Scim\Storage\InMemoryScimUserStorage;
use Vortos\Auth\Scim\Storage\ScimGroupStorageInterface;
use Vortos\Auth\Scim\Storage\ScimUserStorageInterface;
use Vortos\Auth\Scim\Token\InMemoryScimTokenStorage;
use Vortos\Auth\Scim\Token\RedisScimTokenStorage;
use Vortos\Auth\Scim\Token\ScimTokenService;
use Vortos\Auth\Scim\Token\ScimTokenStorageInterface;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Tenant\TenantContext;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Cache\Adapter\RedisConnectionFactory;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Tracing\Contract\TracingInterface;

final class AuthExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_auth';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env = $container->getParameter('kernel.env');

        $config = new VortosAuthConfig();

        $base = $projectDir . '/config/auth.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/auth.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);
        $usesRedis = $resolved['token_storage'] === RedisTokenStorage::class;

        // Exposed for TenantMiddlewareCompilerPass, which registers
        // TenantContextMiddleware after the merge pass (where TenantContext exists).
        $container->setParameter('vortos.auth.tenant_claim', $resolved['tenant_claim']);

        if ($usesRedis) {
            $this->ensureRedisService($container, $env);
        }

        // JwtConfig — signing material comes from the keyring (supports rotation). B21: the keyring is
        // registered as an inline-Definition-built service (never a raw Keyring object argument) so the
        // prod HTTP container dumps via PhpDumper; every consumer references it.
        $container->setDefinition(Keyring::class, $config->keyringDefinition())
            ->setShared(true)->setPublic(false);
        $container->register(JwtConfig::class, JwtConfig::class)
            ->setArguments([
                new Reference(Keyring::class),
                $resolved['access_token_ttl'],
                $resolved['refresh_token_ttl'],
                $resolved['issuer'],
                $resolved['audience'],
            ])
            ->setShared(true)->setPublic(false);

        // JWKS endpoint — publishes public signing keys for RS256 keyrings.
        if ($config->isJwksEnabled()) {
            $container->register(JwksExporter::class, JwksExporter::class)
                ->setArguments([new Reference(Keyring::class)])
                ->setShared(true)->setPublic(false);

            $container->register(JwksController::class, JwksController::class)
                ->setArgument('$exporter', new Reference(JwksExporter::class))
                ->addTag('vortos.api.controller')
                ->setShared(true)->setPublic(true);
        }

        // Token storage
        if ($this->hasRedisService($container)) {
            $container->register(RedisTokenStorage::class, RedisTokenStorage::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
        }

        $container->register(InMemoryTokenStorage::class, InMemoryTokenStorage::class)
            ->setShared(true)->setPublic(false);

        $container->setAlias(TokenStorageInterface::class, $resolved['token_storage'])->setPublic(false);

        // JwtService — $sessionEnforcer is null by default; injected below when Redis is available
        $container->register(JwtService::class, JwtService::class)
            ->setArguments([
                new Reference(JwtConfig::class),
                new Reference(TokenStorageInterface::class),
                null, // $sessionEnforcer — wired below when Redis is available
            ])
            ->setShared(true)->setPublic(true);

        // Password hasher
        $container->register(ArgonPasswordHasher::class, ArgonPasswordHasher::class)
            ->setShared(true)->setPublic(false);
        $container->setAlias(PasswordHasherInterface::class, ArgonPasswordHasher::class)->setPublic(true);

        // PasswordRehashListener — activated when the app registers a RehashableUserPersisterInterface
        $container->register(PasswordRehashListener::class, PasswordRehashListener::class)
            ->setArguments([
                new Reference(PasswordHasherInterface::class),
                new Reference(RehashableUserPersisterInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setShared(true)->setPublic(true);

        // CurrentUserProvider
        $container->register(CurrentUserProvider::class, CurrentUserProvider::class)
            ->setArgument('$arrayAdapter', new Reference(ArrayAdapter::class))
            ->setShared(true)->setPublic(true);

        // Token freshness — min_iat kill-switch
        if ($this->hasRedisService($container)) {
            $container->register(RedisMinIatStore::class, RedisMinIatStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
            $container->setAlias(MinIatStoreInterface::class, RedisMinIatStore::class)->setPublic(false);
        } else {
            $container->register(InMemoryMinIatStore::class, InMemoryMinIatStore::class)
                ->setShared(true)->setPublic(false);
            $container->setAlias(MinIatStoreInterface::class, InMemoryMinIatStore::class)->setPublic(false);
        }

        $container->register(MinIatGuard::class, MinIatGuard::class)
            ->setArguments([
                new Reference(MinIatStoreInterface::class),
                new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setShared(true)->setPublic(false);

        $container->register(CompositeTokenFreshnessGuard::class, CompositeTokenFreshnessGuard::class)
            ->setArgument(0, new Reference(MinIatGuard::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(TokenFreshnessGuardInterface::class, CompositeTokenFreshnessGuard::class)->setPublic(false);

        // AuthMiddleware
        $container->register(AuthMiddleware::class, AuthMiddleware::class)
            ->setArguments([
                new Reference(JwtService::class),
                new Reference(ArrayAdapter::class),
                [], // $protectedControllers — filled by AuthCompilerPass
                new Reference(TokenFreshnessGuardInterface::class),
            ])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Redis-backed stores (when cache or auth registered the \Redis service)
        if ($this->hasRedisService($container)) {
            $container->register(UserQuotaResolver::class, UserQuotaResolver::class)
                ->setShared(true)->setPublic(false);
            $container->register(GlobalQuotaResolver::class, GlobalQuotaResolver::class)
                ->setShared(true)->setPublic(false);

            $container->register(RedisRateLimitStore::class, RedisRateLimitStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);

            $container->register(RateLimitCircuitBreaker::class, RateLimitCircuitBreaker::class)
                ->setArguments([
                    $resolved['rate_limit_circuit_breaker_threshold'],
                    $resolved['rate_limit_circuit_breaker_reset_seconds'],
                ])
                ->setShared(true)->setPublic(false);

            $container->register(ResilientRateLimitStore::class, ResilientRateLimitStore::class)
                ->setArguments([
                    new Reference(RedisRateLimitStore::class),
                    new Reference(RateLimitCircuitBreaker::class),
                ])
                ->setShared(true)->setPublic(false);

            // B21: inline Definition (enums + ints are dumpable), not a raw RateLimitFailureConfig object.
            $rateLimitFailureConfig = new Definition(RateLimitFailureConfig::class, [
                '$ipMode' => RateLimitFailureMode::from($resolved['rate_limit_failure_mode_ip']),
                '$globalMode' => RateLimitFailureMode::from($resolved['rate_limit_failure_mode_global']),
                '$userMode' => RateLimitFailureMode::from($resolved['rate_limit_failure_mode_user']),
                '$circuitBreakerThreshold' => $resolved['rate_limit_circuit_breaker_threshold'],
                '$circuitBreakerResetSeconds' => $resolved['rate_limit_circuit_breaker_reset_seconds'],
            ]);

            $container->register(RedisQuotaStore::class, RedisQuotaStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
            $container->setAlias(QuotaStoreInterface::class, RedisQuotaStore::class)->setPublic(false);

            $container->register(LockoutCircuitBreaker::class, LockoutCircuitBreaker::class)
                ->setArguments([
                    $resolved['lockout_circuit_breaker_threshold'],
                    $resolved['lockout_circuit_breaker_reset_seconds'],
                ])
                ->setShared(true)->setPublic(false);

            $container->register(RedisLockoutStore::class, RedisLockoutStore::class)
                ->setArguments([
                    new Reference(\Redis::class),
                    new Reference(LockoutCircuitBreaker::class),
                ])
                ->setShared(true)->setPublic(false);

            $container->register(RedisSessionStore::class, RedisSessionStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(true);
            $container->setAlias(SessionStoreInterface::class, RedisSessionStore::class)->setPublic(false);

            // SessionEnforcer — wires session limiting into JwtService
            $container->register(SessionEnforcer::class, SessionEnforcer::class)
                ->setArguments([
                    new Reference(SessionStoreInterface::class),
                    new Reference(TokenStorageInterface::class),
                    null, // $policy — filled by SessionCompilerPass
                ])
                ->setShared(true)->setPublic(true);

            $container->getDefinition(JwtService::class)
                ->setArgument('$sessionEnforcer', new Reference(SessionEnforcer::class));

            // LockoutManager
            $lockoutConfig = $config->getLockoutConfig() ?? new \Vortos\Auth\Lockout\LockoutConfig();

            // B21: pass an inline Definition, not the raw LockoutConfig object — the PhpDumper
            // rejects instantiated objects as service arguments and the prod container fails to dump.
            $lockoutConfigDef = (new Definition(\Vortos\Auth\Lockout\LockoutConfig::class))
                ->setProperties([
                    'maxAttempts'         => $lockoutConfig->maxAttempts,
                    'lockDurationSeconds' => $lockoutConfig->lockDurationSeconds,
                    'trackBy'             => $lockoutConfig->trackBy,
                    'message'             => $lockoutConfig->message,
                    'backoffBaseSeconds'  => $lockoutConfig->backoffBaseSeconds,
                    'backoffMaxSeconds'   => $lockoutConfig->backoffMaxSeconds,
                ]);

            $container->register(LockoutKeyNormalizer::class, LockoutKeyNormalizer::class)
                ->setShared(true)->setPublic(false);

            $container->register(LockoutManager::class, LockoutManager::class)
                ->setArguments([
                    new Reference(RedisLockoutStore::class),
                    $lockoutConfigDef,
                    new Reference(LockoutKeyNormalizer::class),
                    LockoutFailureMode::from($resolved['lockout_failure_mode']),
                    new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)->setPublic(true);

            // Rate limit middleware
            $container->register(RateLimitMiddleware::class, RateLimitMiddleware::class)
                ->setArguments([
                    new Reference(CurrentUserProvider::class),
                    new Reference(ResilientRateLimitStore::class),
                    [], // routeMap — filled by RateLimitCompilerPass
                    [], // policies — filled by RateLimitCompilerPass
                    $rateLimitFailureConfig,
                    $resolved['rate_limit_headers'],
                    $resolved['problem_details'],
                    new Reference(IpResolverInterface::class),
                    new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)->setPublic(true)
                ->addTag('kernel.event_subscriber');

            // Quota middleware
            $container->register(QuotaMiddleware::class, QuotaMiddleware::class)
                ->setArguments([
                    new Reference(CurrentUserProvider::class),
                    new Reference(QuotaStoreInterface::class),
                    [],
                    [],
                    [],
                    QuotaFailureMode::from($resolved['quota_failure_mode']),
                    $resolved['quota_headers'],
                    $resolved['problem_details'],
                    $resolved['quota_compensate_on_server_error'],
                    new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)->setPublic(true)
                ->addTag('kernel.event_subscriber');
        }

        // TenantContextMiddleware is registered by TenantMiddlewareCompilerPass
        // (AuthPackage::build), not here: a has(TenantContext::class) check inside
        // load() runs against the per-extension merge container and is always false.

        // Feature access middleware (no Redis required)
        $container->register(FeatureAccessMiddleware::class, FeatureAccessMiddleware::class)
            ->setArguments([
                new Reference(CurrentUserProvider::class),
                [],
                [],
                $resolved['problem_details'],
                new Reference(FrameworkTelemetry::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Audit integrity — hash chain + HMAC signing
        $container->register(AuthAuditHashChain::class, AuthAuditHashChain::class)
            ->setShared(true)->setPublic(false);

        $auditHmacKey = $resolved['audit_hmac_key'] ?? '';

        if ($auditHmacKey !== '' && $this->hasRedisService($container)) {
            $container->register(RedisChainStateStore::class, RedisChainStateStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
            $container->setAlias(ChainStateStoreInterface::class, RedisChainStateStore::class)->setPublic(false);
        } elseif ($auditHmacKey !== '') {
            $container->register(InMemoryChainStateStore::class, InMemoryChainStateStore::class)
                ->setShared(true)->setPublic(false);
            $container->setAlias(ChainStateStoreInterface::class, InMemoryChainStateStore::class)->setPublic(false);
        }

        $container->register(AuthAuditChainVerifier::class, AuthAuditChainVerifier::class)
            ->setArgument('$chain', new Reference(AuthAuditHashChain::class))
            ->setShared(true)->setPublic(false);

        $container->register(VerifyAuditChainCommand::class, VerifyAuditChainCommand::class)
            ->setArguments([
                new Reference(AuditChainReaderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference(AuthAuditChainVerifier::class),
                $auditHmacKey,
            ])
            ->addTag('console.command')
            ->setPublic(false);

        // Audit middleware
        $container->register(AuditMiddleware::class, AuditMiddleware::class)
            ->setArguments([
                new Reference(CurrentUserProvider::class),
                null, // $store — wired by AuditCompilerPass when an AuditStoreInterface is registered
                [],   // $routeMap — filled by AuditCompilerPass
                AuditFailureMode::from($resolved['audit_failure_mode']),
                new Reference(IpResolverInterface::class),
                new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $auditHmacKey !== '' ? new Reference(AuthAuditHashChain::class) : null,
                $auditHmacKey !== '' ? new Reference(ChainStateStoreInterface::class) : null,
                $auditHmacKey,
            ])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // 2FA middleware
        $container->register(TwoFactorMiddleware::class, TwoFactorMiddleware::class)
            ->setArguments([new Reference(CurrentUserProvider::class), null, []])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // API Key authentication (M2M / server-to-server)
        if ($this->hasRedisService($container)) {
            $container->register(RedisApiKeyStorage::class, RedisApiKeyStorage::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
            $container->setAlias(ApiKeyStorageInterface::class, RedisApiKeyStorage::class)->setPublic(false);

            $maxInactivity = $resolved['api_key_max_inactivity_seconds'];
            $container->register(ApiKeyService::class, ApiKeyService::class)
                ->setArguments([
                    new Reference(ApiKeyStorageInterface::class),
                    $maxInactivity > 0 ? $maxInactivity : null,
                ])
                ->setShared(true)->setPublic(true);

            $container->register(ApiKeyAuthMiddleware::class, ApiKeyAuthMiddleware::class)
                ->setArguments([
                    new Reference(ApiKeyService::class),
                    [], // routeMap — filled by ApiKeyCompilerPass
                ])
                ->addTag('kernel.event_subscriber')
                ->setShared(true)->setPublic(true);
        }

        // SCIM 2.0 provisioning — token auth, controller, service, storage
        if ($this->hasRedisService($container)) {
            $container->register(RedisScimTokenStorage::class, RedisScimTokenStorage::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
            $container->setAlias(ScimTokenStorageInterface::class, RedisScimTokenStorage::class)->setPublic(false);
        } else {
            $container->register(InMemoryScimTokenStorage::class, InMemoryScimTokenStorage::class)
                ->setShared(true)->setPublic(false);
            $container->setAlias(ScimTokenStorageInterface::class, InMemoryScimTokenStorage::class)->setPublic(false);
        }

        $container->register(ScimTokenService::class, ScimTokenService::class)
            ->setArgument('$storage', new Reference(ScimTokenStorageInterface::class))
            ->setShared(true)->setPublic(true);

        $container->register(InMemoryScimUserStorage::class, InMemoryScimUserStorage::class)
            ->setShared(true)->setPublic(false);
        $container->setAlias(ScimUserStorageInterface::class, InMemoryScimUserStorage::class)->setPublic(false);

        $container->register(InMemoryScimGroupStorage::class, InMemoryScimGroupStorage::class)
            ->setShared(true)->setPublic(false);
        $container->setAlias(ScimGroupStorageInterface::class, InMemoryScimGroupStorage::class)->setPublic(false);

        $container->register(ScimRoleGuard::class, ScimRoleGuard::class)
            ->setShared(true)->setPublic(false);

        $container->register(ScimAuditLogger::class, ScimAuditLogger::class)
            ->setArgument('$store', new Reference(\Vortos\Auth\Audit\Contract\AuditStoreInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setShared(true)->setPublic(false);

        $container->register(ScimService::class, ScimService::class)
            ->setArguments([
                new Reference(ScimUserStorageInterface::class),
                new Reference(ScimGroupStorageInterface::class),
                new Reference(TenantContext::class),
                null, // $roleMapper — wired by app config when ClaimsRoleMapper is registered
                new Reference(ScimRoleGuard::class),
                new Reference(ScimAuditLogger::class),
            ])
            ->setShared(true)->setPublic(true);

        $container->register(ScimController::class, ScimController::class)
            ->setArgument('$service', new Reference(ScimService::class))
            ->addTag('vortos.api.controller')
            ->addTag('vortos.scim.route')
            ->setShared(true)->setPublic(true);

        $container->register(ScimDiscoveryController::class, ScimDiscoveryController::class)
            ->addTag('vortos.api.controller')
            ->addTag('vortos.scim.route')
            ->setShared(true)->setPublic(true);

        $container->register(ScimAuthMiddleware::class, ScimAuthMiddleware::class)
            ->setArguments([
                new Reference(ScimTokenService::class),
                new Reference(TenantContext::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
                    ?? throw new \LogicException('SCIM auth requires TenantContext'),
                [], // routeMap — filled by ScimCompilerPass
                new Reference(IpResolverInterface::class),
            ])
            ->addTag('kernel.event_subscriber')
            ->setShared(true)->setPublic(true);

        // Key management console commands
        $container->register(KeysListCommand::class, KeysListCommand::class)
            ->setArgument('$config', new Reference(JwtConfig::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(KeysGenerateCommand::class, KeysGenerateCommand::class)
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(RevokeAllTokensCommand::class, RevokeAllTokensCommand::class)
            ->setArgument('$store', new Reference(MinIatStoreInterface::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register('vortos.config_stub.auth', ConfigStub::class)
            ->setArguments(['auth', __DIR__ . '/../stubs/auth.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);
    }

    private function ensureRedisService(ContainerBuilder $container, string $env): void
    {
        if ($this->hasRedisService($container)) {
            return;
        }

        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('RedisTokenStorage requires the ext-redis PHP extension.');
        }

        if (!class_exists(RedisConnectionFactory::class)) {
            throw new \RuntimeException('RedisTokenStorage requires vortos-cache RedisConnectionFactory to create the Redis connection.');
        }

        $dsn = $_ENV['VORTOS_AUTH_REDIS_DSN']
            ?? $_ENV['VORTOS_CACHE_DSN']
            ?? ($env === 'prod' ? null : 'redis://127.0.0.1:6379');

        if ($dsn === null || $dsn === '') {
            throw new \RuntimeException('RedisTokenStorage requires VORTOS_AUTH_REDIS_DSN or VORTOS_CACHE_DSN.');
        }

        $container->register(\Redis::class, \Redis::class)
            ->setFactory([RedisConnectionFactory::class, 'fromDsn'])
            ->setArguments([$dsn])
            ->setShared(true)
            ->setPublic(false);
    }

    private function hasRedisService(ContainerBuilder $container): bool
    {
        return $container->hasDefinition(\Redis::class) || $container->hasAlias(\Redis::class);
    }
}
