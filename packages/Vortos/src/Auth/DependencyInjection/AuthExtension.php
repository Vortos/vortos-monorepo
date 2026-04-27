<?php
declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Controller\LoginController;
use Vortos\Auth\Controller\LogoutController;
use Vortos\Auth\Controller\RefreshTokenController;
use Vortos\Auth\Hasher\ArgonPasswordHasher;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Lockout\Storage\RedisLockoutStore;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Auth\Quota\Middleware\QuotaMiddleware;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;
use Vortos\Auth\RateLimit\Middleware\RateLimitMiddleware;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;
use Vortos\Auth\FeatureAccess\Middleware\FeatureAccessMiddleware;
use Vortos\Auth\Audit\Middleware\AuditMiddleware;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;
use Vortos\Auth\Session\Storage\RedisSessionStore;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Auth\Storage\RedisTokenStorage;
use Vortos\Cache\Adapter\ArrayAdapter;

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
        if (extension_loaded('redis')) {
            $container->register(RedisTokenStorage::class, RedisTokenStorage::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);
        }

        $container->register(InMemoryTokenStorage::class, InMemoryTokenStorage::class)
            ->setShared(true)->setPublic(false);

        $container->setAlias(TokenStorageInterface::class, $resolved['token_storage'])->setPublic(false);

        // JwtService
        $container->register(JwtService::class, JwtService::class)
            ->setArguments([new Reference(JwtConfig::class), new Reference(TokenStorageInterface::class)])
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
            ->setArguments([new Reference(JwtService::class), new Reference(ArrayAdapter::class)])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Redis-backed stores (only when Redis available)
        if (extension_loaded('redis')) {
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
                ])
                ->setShared(true)->setPublic(true)
                ->addTag('kernel.event_subscriber');
        }

        // Feature access middleware (no Redis required)
        $container->register(FeatureAccessMiddleware::class, FeatureAccessMiddleware::class)
            ->setArguments([new Reference(CurrentUserProvider::class), [], []])
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

        // Built-in controllers
        if ($resolved['enable_built_in_controllers']) {
            foreach ([LoginController::class, RefreshTokenController::class, LogoutController::class] as $ctrl) {
                $container->register($ctrl, $ctrl)
                    ->setAutowired(true)->setPublic(true)
                    ->addTag('controller.service_arguments');
            }
        }
    }
}
