<?php

declare(strict_types=1);

namespace Vortos\Auth\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Vortos\Auth\Contract\PasswordHasherInterface;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Controller\LoginController;
use Vortos\Auth\Controller\LogoutController;
use Vortos\Auth\Controller\RefreshTokenController;
use Vortos\Auth\Hasher\ArgonPasswordHasher;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\JwtService;
use Vortos\Auth\Middleware\AuthMiddleware;
use Vortos\Auth\Storage\InMemoryTokenStorage;
use Vortos\Auth\Storage\RedisTokenStorage;
use Vortos\Cache\Adapter\ArrayAdapter;

/**
 * Wires all auth services.
 *
 * Loads config/auth.php and config/{env}/auth.php.
 *
 * ## Services registered
 *
 *   JwtConfig                 — immutable config value object
 *   JwtService                — token generation, validation, refresh
 *   ArgonPasswordHasher       — Argon2id password hashing
 *   PasswordHasherInterface   — alias → ArgonPasswordHasher
 *   RedisTokenStorage         — production refresh token storage
 *   InMemoryTokenStorage      — testing
 *   TokenStorageInterface     — alias → configured storage
 *   CurrentUserProvider       — retrieves identity from ArrayAdapter
 *   AuthMiddleware            — HTTP middleware for token validation
 *
 * ## AuthMiddleware wiring
 *
 * AuthMiddleware wraps the inner HTTP kernel. Register it as a decorator
 * in your Runner by wrapping the kernel after compilation.
 *
 * In Runner::getContainer():
 *   $kernel = $container->get('vortos');
 *   $authMiddleware = $container->get(AuthMiddleware::class);
 *   $authMiddleware->setInnerKernel($kernel);
 *   // or configure via kernel.decorated pattern
 */
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

        // JwtConfig value object
        $container->register(JwtConfig::class, JwtConfig::class)
            ->setArguments([
                $resolved['secret'],
                $resolved['access_token_ttl'],
                $resolved['refresh_token_ttl'],
                $resolved['issuer'],
            ])
            ->setShared(true)
            ->setPublic(false);

        // Token storage implementations
        if (extension_loaded('redis')) {
        $container->register(RedisTokenStorage::class, RedisTokenStorage::class)
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setShared(true)
            ->setPublic(false);
        }   

        $container->register(InMemoryTokenStorage::class, InMemoryTokenStorage::class)
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(TokenStorageInterface::class, $resolved['token_storage'])
            ->setPublic(false);

        // JwtService
        $container->register(JwtService::class, JwtService::class)
            ->setArguments([
                new Reference(JwtConfig::class),
                new Reference(TokenStorageInterface::class),
            ])
            ->setShared(true)
            ->setPublic(true);

        // Password hasher
        $container->register(ArgonPasswordHasher::class, ArgonPasswordHasher::class)
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PasswordHasherInterface::class, ArgonPasswordHasher::class)
            ->setPublic(true);

        // CurrentUserProvider
        $container->register(CurrentUserProvider::class, CurrentUserProvider::class)
            ->setArgument('$arrayAdapter', new Reference(ArrayAdapter::class))
            ->setShared(true)
            ->setPublic(true);

        // AuthMiddleware
        $container->register(AuthMiddleware::class, AuthMiddleware::class)
            ->setArguments([
                new Reference(JwtService::class),
                new Reference(ArrayAdapter::class),
        ])
            ->setShared(true)
            ->setPublic(true)
            ->addTag('kernel.event_subscriber');

        if ($resolved['enable_built_in_controllers']) {
            $container->register(LoginController::class, LoginController::class)
                ->setAutowired(true)
                ->setPublic(true)
                ->addTag('controller.service_arguments');

            $container->register(RefreshTokenController::class, RefreshTokenController::class)
                ->setAutowired(true)
                ->setPublic(true)
                ->addTag('controller.service_arguments');

            $container->register(LogoutController::class, LogoutController::class)
                ->setAutowired(true)
                ->setPublic(true)
                ->addTag('controller.service_arguments');
        }
    }
}
