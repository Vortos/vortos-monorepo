<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory as GuzzleHttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Binds a default PSR-18 HTTP client and PSR-17 message factories when the application
 * has not already provided them.
 *
 * The deploy stack (Caddy admin, GitHub OIDC, SSH-CA, registry token exchange) type-hints
 * the PSR interfaces but the framework historically shipped no default binding, so a stock
 * container failed with ServiceNotFoundException. Guzzle 7 — a framework dependency — supplies
 * both a PSR-18 client (GuzzleHttp\Client) and the PSR-17 factories (GuzzleHttp\Psr7\HttpFactory).
 *
 * This runs in a compiler pass (not load()) so that has()/hasAlias() reliably reflect every
 * extension's and the application's bindings; an app-provided client therefore always wins.
 * It is a default, never an override.
 */
final class HttpClientDefaultsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->bindClient($container);
        $this->bindMessageFactories($container);
    }

    private function bindClient(ContainerBuilder $container): void
    {
        if ($this->alreadyBound($container, ClientInterface::class)) {
            return;
        }

        if (!class_exists(GuzzleClient::class)) {
            return;
        }

        if (!$container->hasDefinition(GuzzleClient::class)) {
            $container->register(GuzzleClient::class, GuzzleClient::class)->setPublic(false);
        }

        $container->setAlias(ClientInterface::class, GuzzleClient::class)->setPublic(false);
    }

    private function bindMessageFactories(ContainerBuilder $container): void
    {
        if (!class_exists(GuzzleHttpFactory::class)) {
            return;
        }

        // GuzzleHttp\Psr7\HttpFactory implements all four PSR-17 factory contracts.
        $factories = [
            RequestFactoryInterface::class,
            StreamFactoryInterface::class,
            UriFactoryInterface::class,
            ResponseFactoryInterface::class,
        ];

        $needsDefinition = false;
        foreach ($factories as $contract) {
            if (!$this->alreadyBound($container, $contract)) {
                $needsDefinition = true;
                break;
            }
        }

        if (!$needsDefinition) {
            return;
        }

        if (!$container->hasDefinition(GuzzleHttpFactory::class)) {
            $container->register(GuzzleHttpFactory::class, GuzzleHttpFactory::class)->setPublic(false);
        }

        foreach ($factories as $contract) {
            if (!$this->alreadyBound($container, $contract)) {
                $container->setAlias($contract, GuzzleHttpFactory::class)->setPublic(false);
            }
        }
    }

    private function alreadyBound(ContainerBuilder $container, string $id): bool
    {
        return $container->hasAlias($id) || $container->hasDefinition($id);
    }
}
