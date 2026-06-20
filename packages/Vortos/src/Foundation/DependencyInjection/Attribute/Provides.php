<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

/**
 * Marks a public method on a #[ServiceProvider] class as a named service factory.
 *
 * The method's return value becomes the service registered under $serviceId.
 * Method parameters are resolved by ServiceProviderCompilerPass:
 *
 *   - #[InjectService('id')] TypeHint $p   → Reference to named service 'id'
 *   - #[Value('param.name')] string $p     → Container parameter expression
 *   - TypeHint $p (no attribute)           → Reference to service by type (FQCN)
 *   - No type, no attribute                → compile-time LogicException
 *
 * Reserved prefixes that cannot be used as $serviceId:
 *   container., kernel., service_container, vortos.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Provides
{
    public function __construct(public readonly string $serviceId) {}
}
