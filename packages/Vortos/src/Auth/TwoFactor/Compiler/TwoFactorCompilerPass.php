<?php
declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\TwoFactor\Attribute\Requires2FA;
use Vortos\Auth\TwoFactor\Contract\TwoFactorVerifierInterface;
use Vortos\Auth\TwoFactor\Middleware\TwoFactorMiddleware;

/**
 * Resolves the application's canonical {@see TwoFactorVerifierInterface} and wires it into
 * {@see TwoFactorMiddleware} (which enforces #[Requires2FA]).
 *
 * Resolution order (deterministic — never "pick whichever definition happens to be first"):
 *   1. An explicit binding of the interface itself (an app alias or definition for
 *      TwoFactorVerifierInterface) always wins. This is the ONLY safe answer when several
 *      implementations coexist, since only the app knows which is canonical.
 *   2. Otherwise, if exactly ONE concrete implementation exists, it is canonical — and an
 *      alias for the interface is registered so ANY consumer (e.g. the flags-admin
 *      AdminAuthMiddleware step-up) can autowire it, not just this middleware.
 *   3. Otherwise (zero, or several with no explicit binding) there is no canonical verifier.
 *
 * Fail-fast: if any #[Requires2FA] controller exists but no canonical verifier could be
 * resolved, the container build throws with an actionable message — a silent "pick one"
 * could bind the wrong factor (e.g. TOTP where a platform FIDO2 key is required), and a
 * silent null would defeat the gate at runtime.
 */
final class TwoFactorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TwoFactorMiddleware::class)) {
            return;
        }

        $impls             = $this->discoverImplementations($container);
        $verifierServiceId = $this->resolveCanonical($container, $impls);

        // Publish a canonical alias so every interface consumer resolves it (not only this
        // middleware) — but never clobber an explicit app-provided binding.
        if ($verifierServiceId !== null
            && !$container->hasAlias(TwoFactorVerifierInterface::class)
            && !$container->hasDefinition(TwoFactorVerifierInterface::class)
        ) {
            $container->setAlias(TwoFactorVerifierInterface::class, $verifierServiceId)
                ->setPublic(false);
        }

        $protectedControllers = $this->collectProtectedControllers($container);

        if (!empty($protectedControllers) && $verifierServiceId === null) {
            throw new \RuntimeException($this->misconfigurationMessage($protectedControllers, $impls));
        }

        $container->getDefinition(TwoFactorMiddleware::class)
            ->setArgument('$verifier', $verifierServiceId ? new Reference($verifierServiceId) : null)
            ->setArgument('$protectedControllers', $protectedControllers);
    }

    /**
     * @return array<string,string> serviceId => class, for every concrete verifier definition
     *                              (excludes the interface's own binding, handled separately)
     */
    private function discoverImplementations(ContainerBuilder $container): array
    {
        $impls = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            $class = $definition->getClass();
            if (!$class || $class === TwoFactorVerifierInterface::class || !class_exists($class)) {
                continue;
            }

            if (is_a($class, TwoFactorVerifierInterface::class, true)) {
                $impls[$serviceId] = $class;
            }
        }

        return $impls;
    }

    /**
     * @param array<string,string> $impls
     */
    private function resolveCanonical(ContainerBuilder $container, array $impls): ?string
    {
        // 1. Explicit binding of the interface wins (an app-declared canonical verifier).
        if ($container->hasAlias(TwoFactorVerifierInterface::class)) {
            return (string) $container->getAlias(TwoFactorVerifierInterface::class);
        }
        if ($container->hasDefinition(TwoFactorVerifierInterface::class)) {
            return TwoFactorVerifierInterface::class;
        }

        // 2. Exactly one implementation ⇒ unambiguous canonical.
        if (count($impls) === 1) {
            return array_key_first($impls);
        }

        // 3. Zero, or several-with-no-explicit-binding ⇒ no canonical verifier.
        return null;
    }

    /**
     * @return list<string>
     */
    private function collectProtectedControllers(ContainerBuilder $container): array
    {
        $protectedControllers = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }
            if (!$definition->hasTag('vortos.api.controller')
                && !$definition->hasTag('controller.service_arguments')
            ) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (!empty($reflection->getAttributes(Requires2FA::class))) {
                $protectedControllers[] = $class;
                continue;
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!empty($method->getAttributes(Requires2FA::class))) {
                    $protectedControllers[] = $class;
                    break;
                }
            }
        }

        return $protectedControllers;
    }

    /**
     * @param list<string>         $protectedControllers
     * @param array<string,string> $impls
     */
    private function misconfigurationMessage(array $protectedControllers, array $impls): string
    {
        if (count($impls) > 1) {
            return sprintf(
                '%d controller(s) require 2FA via #[Requires2FA] but %d %s implementations are registered (%s) ' .
                'with no canonical binding. Alias the interface to your canonical verifier so the choice is ' .
                'explicit, e.g. $services->alias(%s::class, YourVerifier::class).',
                count($protectedControllers),
                count($impls),
                TwoFactorVerifierInterface::class,
                implode(', ', array_values($impls)),
                TwoFactorVerifierInterface::class,
            );
        }

        return sprintf(
            '%d controller(s) require 2FA via #[Requires2FA] but no %s implementation is registered. ' .
            'Implement the interface and register it as a service.',
            count($protectedControllers),
            TwoFactorVerifierInterface::class,
        );
    }
}
