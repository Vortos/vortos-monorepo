<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\DependencyInjection\Attribute\InjectService;
use Vortos\Foundation\DependencyInjection\Attribute\Provides;
use Vortos\Foundation\DependencyInjection\Attribute\Value;

/**
 * Registers named services declared with #[Provides] on #[ServiceProvider] classes.
 *
 * For each tagged provider, this pass reflects on public methods and for every
 * method carrying #[Provides($serviceId)]:
 *
 *   1. Validates $serviceId against reserved prefixes.
 *   2. Resolves the method return type as the registered service class.
 *   3. Resolves each method parameter to a container argument:
 *        #[InjectService('id')] → Reference('id')
 *        #[Value(...)]          → the Autowire value string/expression
 *        typed, no attribute    → Reference($typeName)
 *        untyped, no attribute  → LogicException
 *   4. Registers a factory service unless an explicit definition/alias already exists.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 15 — after DomainServiceCompilerPass (20),
 * before DefaultImplCompilerPass (5).
 */
final class ServiceProviderCompilerPass implements CompilerPassInterface
{
    /** @var string[] Service ID prefixes that may not be used in #[Provides]. */
    private const RESERVED_PREFIXES = [
        'container.',
        'kernel.',
        'service_container',
        'vortos.',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('vortos.service_provider') as $providerServiceId => $_) {
            $definition = $container->getDefinition($providerServiceId);
            $className  = $definition->getClass() ?? $providerServiceId;

            if (!class_exists($className)) {
                continue;
            }

            $reflClass = new \ReflectionClass($className);

            foreach ($reflClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $providesAttrs = $method->getAttributes(Provides::class);

                if (empty($providesAttrs)) {
                    continue;
                }

                /** @var Provides $provides */
                $provides  = $providesAttrs[0]->newInstance();
                $serviceId = $provides->serviceId;

                $this->assertServiceIdNotReserved($serviceId, $className, $method->getName());

                // Explicit definition or alias wins — do not override.
                if ($container->hasDefinition($serviceId) || $container->hasAlias($serviceId)) {
                    continue;
                }

                $returnTypeName = $this->resolveReturnType($method, $className);
                $arguments      = $this->resolveArguments($method, $className);

                $container->register($serviceId, $returnTypeName)
                    ->setFactory([new Reference($providerServiceId), $method->getName()])
                    ->setArguments($arguments)
                    ->setPublic(false);
            }
        }
    }

    private function assertServiceIdNotReserved(string $serviceId, string $className, string $methodName): void
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($serviceId, $prefix)) {
                throw new \LogicException(sprintf(
                    '#[Provides("%s")] on %s::%s() uses a reserved service ID prefix "%s". '
                    . 'Choose a non-reserved service ID.',
                    $serviceId,
                    $className,
                    $methodName,
                    $prefix,
                ));
            }
        }
    }

    private function resolveReturnType(\ReflectionMethod $method, string $className): string
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof \ReflectionNamedType) {
            throw new \LogicException(sprintf(
                '#[Provides] method %s::%s() must declare a single named return type.',
                $className,
                $method->getName(),
            ));
        }

        $name = $returnType->getName();

        if ($name === 'void' || $name === 'never' || $returnType->isBuiltin()) {
            throw new \LogicException(sprintf(
                '#[Provides] method %s::%s() has invalid return type "%s". '
                . 'It must return a class or interface.',
                $className,
                $method->getName(),
                $name,
            ));
        }

        return $name;
    }

    /** @return array<int, mixed> */
    private function resolveArguments(\ReflectionMethod $method, string $className): array
    {
        $args = [];

        foreach ($method->getParameters() as $param) {
            $args[] = $this->resolveParameter($param, $className, $method->getName());
        }

        return $args;
    }

    private function resolveParameter(
        \ReflectionParameter $param,
        string $className,
        string $methodName,
    ): mixed {
        // #[InjectService] — explicit named service reference
        $injectAttrs = $param->getAttributes(InjectService::class);
        if (!empty($injectAttrs)) {
            return $injectAttrs[0]->newInstance()->value; // Reference instance
        }

        // #[Value] — container parameter or env expression
        $valueAttrs = $param->getAttributes(Value::class);
        if (!empty($valueAttrs)) {
            return $valueAttrs[0]->newInstance()->value; // string or Expression
        }

        // Typed with no attribute — autowire by type
        $type = $param->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return new Reference($type->getName());
        }

        throw new \LogicException(sprintf(
            'Parameter $%s of %s::%s() has no type and no #[InjectService] or #[Value] attribute. '
            . 'Add a type hint or an attribute to declare how to inject it.',
            $param->getName(),
            $className,
            $methodName,
        ));
    }
}
