<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Hasher\ArgonPasswordHasher;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Lockout\Storage\RedisLockoutStore;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Auth\Quota\Middleware\QuotaMiddleware;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Quota\Resolver\GlobalQuotaResolver;
use Vortos\Auth\Quota\Resolver\UserQuotaResolver;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;
use Vortos\Auth\RateLimit\Middleware\RateLimitMiddleware;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;
use Vortos\Auth\FeatureAccess\Middleware\FeatureAccessMiddleware;
use Vortos\Auth\Audit\Middleware\AuditMiddleware;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;
use Vortos\Auth\Session\Compiler\SessionCompilerPass;
use Vortos\Auth\Session\SessionEnforcer;
use Vortos\Auth\Session\Storage\RedisSessionStore;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Auth\Storage\RedisTokenStorage;
use Vortos\Auth\ApiKey\ApiKeyService;
use Vortos\Auth\ApiKey\Middleware\ApiKeyAuthMiddleware;
use Vortos\Auth\ApiKey\Storage\ApiKeyStorageInterface;
use Vortos\Auth\ApiKey\Storage\RedisApiKeyStorage;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Cache\Adapter\RedisConnectionFactory;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;
use Vortos\Metrics\Contract\MetricsInterface;
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

        if ($usesRedis) {
            $this->ensureRedisService($container, $env);
        }

        // JwtConfig
        $container->register(JwtConfig::class, JwtConfig::class)
            ->setArguments([
                $resolved['secret'],
                $resolved['access_token_ttl'],
                $resolved['refresh_token_ttl'],
                $resolved['issuer'],
            ])
            ->setShared(true)->setPublic(false);

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

        // CurrentUserProvider
        $container->register(CurrentUserProvider::class, CurrentUserProvider::class)
            ->setArgument('$arrayAdapter', new Reference(ArrayAdapter::class))
            ->setShared(true)->setPublic(true);

        // AuthMiddleware
        $container->register(AuthMiddleware::class, AuthMiddleware::class)
            ->setArguments([
                new Reference(JwtService::class),
                new Reference(ArrayAdapter::class),
                [], // $protectedControllers — filled by AuthCompilerPass
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

            $container->register(RedisQuotaStore::class, RedisQuotaStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);

            $container->register(RedisLockoutStore::class, RedisLockoutStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);

            $container->register(RedisSessionStore::class, RedisSessionStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(true);

            // SessionEnforcer — wires session limiting into JwtService
            $container->register(SessionEnforcer::class, SessionEnforcer::class)
                ->setArguments([
                    new Reference(RedisSessionStore::class),
                    new Reference(TokenStorageInterface::class),
                    null, // $policy — filled by SessionCompilerPass
                ])
                ->setShared(true)->setPublic(true);

            $container->getDefinition(JwtService::class)
                ->setArgument('$sessionEnforcer', new Reference(SessionEnforcer::class));

            // LockoutManager
            $lockoutConfig = $config->getLockoutConfig() ?? new \Vortos\Auth\Lockout\LockoutConfig();
            $container->register(LockoutManager::class, LockoutManager::class)
                ->setArguments([new Reference(RedisLockoutStore::class), $lockoutConfig])
                ->setShared(true)->setPublic(true);

            // Rate limit middleware
            $container->register(RateLimitMiddleware::class, RateLimitMiddleware::class)
                ->setArguments([
                    new Reference(CurrentUserProvider::class),
                    new Reference(RedisRateLimitStore::class),
                    [], // routeMap — filled by RateLimitCompilerPass
                    [], // policies — filled by RateLimitCompilerPass
                    $resolved['rate_limit_headers'],
                    $resolved['problem_details'],
                    new Reference(MetricsInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)->setPublic(true)
                ->addTag('kernel.event_subscriber');

            // Quota middleware
            $container->register(QuotaMiddleware::class, QuotaMiddleware::class)
                ->setArguments([
                    new Reference(CurrentUserProvider::class),
                    new Reference(RedisQuotaStore::class),
                    [],
                    [],
                    [],
                    QuotaFailureMode::from($resolved['quota_failure_mode']),
                    $resolved['quota_headers'],
                    $resolved['problem_details'],
                    new Reference(MetricsInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setShared(true)->setPublic(true)
                ->addTag('kernel.event_subscriber');
        }

        // Feature access middleware (no Redis required)
        $container->register(FeatureAccessMiddleware::class, FeatureAccessMiddleware::class)
            ->setArguments([
                new Reference(CurrentUserProvider::class),
                [],
                [],
                $resolved['problem_details'],
                new Reference(MetricsInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('vortos.logger.security', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference(TracingInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Audit middleware
        $container->register(AuditMiddleware::class, AuditMiddleware::class)
            ->setArguments([new Reference(CurrentUserProvider::class), null, []])
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

            $container->register(ApiKeyService::class, ApiKeyService::class)
                ->setArgument('$storage', new Reference(ApiKeyStorageInterface::class))
                ->setShared(true)->setPublic(true);

            $container->register(ApiKeyAuthMiddleware::class, ApiKeyAuthMiddleware::class)
                ->setArguments([
                    new Reference(ApiKeyService::class),
                    [], // routeMap — filled by ApiKeyCompilerPass
                ])
                ->addTag('kernel.event_subscriber')
                ->setShared(true)->setPublic(true);
        }

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
