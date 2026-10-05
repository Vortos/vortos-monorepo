<?php

declare(strict_types=1);

namespace Vortos\Security\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Security\Cors\Attribute\Cors;
use Vortos\Security\Cors\Middleware\CorsMiddleware;

/**
 * Scans controllers for #[Cors] at compile time and builds the per-route override map
 * {@see CorsMiddleware} resolves against. Zero reflection at runtime.
 *
 * Without this pass the middleware's routeMap stays the empty array it is registered with, so
 * every #[Cors] override is silently ignored and a route marked public-but-non-credentialed is
 * still judged by the global (credentialed) allowlist — the attribute is documented but inert.
 * This mirrors {@see IpFilterCompilerPass}, which already does exactly this for #[AllowIp]/#[DenyIp];
 * CORS was the one per-route override that never got its builder.
 *
 * The map is keyed by controller class and by `Class::method`, which is what
 * {@see CorsMiddleware::resolveConfig()} looks up (via the `_cors_owner` the preflight pass sets,
 * or the resolved `_controller`). Only the attribute properties that were actually set become keys,
 * so an override changes just the facets it names and inherits the rest of the global config.
 */
final class CorsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CorsMiddleware::class)) {
            return;
        }

        $routeMap = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            // Method-level overrides are the most specific.
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attrs = $method->getAttributes(Cors::class);
                if ($attrs !== []) {
                    $routeMap[$class . '::' . $method->getName()] = $this->toOverride($attrs[0]->newInstance());
                }
            }

            // Class-level override applies to every action the controller serves.
            $classAttrs = $reflection->getAttributes(Cors::class);
            if ($classAttrs !== []) {
                $routeMap[$class] = $this->toOverride($classAttrs[0]->newInstance());
            }
        }

        $container->getDefinition(CorsMiddleware::class)
            ->setArgument('$routeMap', $routeMap);
    }

    /**
     * Translate the attribute into the config-array shape CorsMiddleware merges over the global
     * config. Unset (null) properties are omitted so they fall through to the global value — the
     * middleware's own array_filter also drops nulls, and omitting them keeps the map small and
     * its intent legible.
     *
     * @return array<string, mixed>
     */
    private function toOverride(Cors $cors): array
    {
        $map = [
            'origins'         => $cors->origins,
            'methods'         => $cors->methods,
            'allowed_headers' => $cors->allowedHeaders,
            'exposed_headers' => $cors->exposedHeaders,
            'credentials'     => $cors->credentials,
            'max_age'         => $cors->maxAge,
        ];

        return array_filter($map, static fn (mixed $v): bool => $v !== null);
    }
}
