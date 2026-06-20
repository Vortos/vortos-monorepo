<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

use Vortos\Foundation\DependencyInjection\Enum\CompilerPassType;

/**
 * Marks a class as a Symfony compiler pass that self-registers into the container.
 *
 * The annotated class MUST implement CompilerPassInterface. It MUST have a
 * no-argument constructor — compiler passes run before the container is compiled
 * and cannot have runtime dependencies.
 *
 * CompilerPassDiscoveryPass picks up this attribute (via the vortos.compiler_pass
 * tag applied by FoundationExtension autoconfiguration) and calls
 * ContainerBuilder::addCompilerPass() with the type and priority declared here.
 *
 * Usage:
 *
 *   #[AsCompilerPass(type: CompilerPassType::BeforeOptimization, priority: 85)]
 *   final class MyPass implements CompilerPassInterface { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsCompilerPass
{
    public function __construct(
        public readonly CompilerPassType $type     = CompilerPassType::BeforeOptimization,
        public readonly int              $priority = 0,
    ) {}
}
