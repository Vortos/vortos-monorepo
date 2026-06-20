<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Enum;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;

/**
 * Typesafe wrapper around Symfony's PassConfig::TYPE_* string constants.
 *
 * String values are identical to the corresponding PassConfig constants so they
 * can be forwarded directly to ContainerBuilder::addCompilerPass() without a
 * translation map. The test suite enforces this equivalence as a regression guard.
 */
enum CompilerPassType: string
{
    case BeforeOptimization = 'beforeOptimization'; // PassConfig::TYPE_BEFORE_OPTIMIZATION
    case Optimize           = 'optimization';        // PassConfig::TYPE_OPTIMIZE
    case BeforeRemoving     = 'beforeRemoving';      // PassConfig::TYPE_BEFORE_REMOVING
    case Remove             = 'removing';            // PassConfig::TYPE_REMOVE
    case AfterRemoving      = 'afterRemoving';       // PassConfig::TYPE_AFTER_REMOVING
}
