<?php
declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\TwoFactor\Attribute\Requires2FA;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;

final class TwoFactorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TwoFactorMiddleware::class)) return;

        $protectedControllers = [];
        $verifierServiceId = null;

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (is_a($class, TwoFactorVerifierInterface::class, true)) {
                $verifierServiceId = $serviceId;
                break;
            }
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) continue;
            if (!$definition->hasTag('vortos.api.controller') &&
                !$definition->hasTag('controller.service_arguments')) continue;

            $reflection = new \ReflectionClass($class);

            // Class-level
            if (!empty($reflection->getAttributes(Requires2FA::class))) {
                $protectedControllers[] = $class;
                continue;
            }

            // Method-level — if any method requires 2FA, the whole controller is protected
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!empty($method->getAttributes(Requires2FA::class))) {
                    $protectedControllers[] = $class;
                    break;
                }
            }
        }

        // Fail fast — a #[Requires2FA] controller with no verifier registered is a misconfiguration
        if (!empty($protectedControllers) && $verifierServiceId === null) {
            throw new \RuntimeException(sprintf(
                '%d controller(s) require 2FA via #[Requires2FA] but no %s implementation is registered. ' .
                'Implement the interface and register it as a service.',
                count($protectedControllers),
                TwoFactorVerifierInterface::class,
            ));
        }

        $verifierRef = $verifierServiceId ? new Reference($verifierServiceId) : null;

        $container->getDefinition(TwoFactorMiddleware::class)
            ->setArgument('$verifier', $verifierRef)
            ->setArgument('$protectedControllers', $protectedControllers);
    }
}
