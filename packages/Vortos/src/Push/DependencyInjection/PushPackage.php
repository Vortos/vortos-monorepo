<?php

declare(strict_types=1);

namespace Vortos\Push\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Contract\PackageInterface;

/**
 * Push package. Registers the Web Push services (see PushExtension). Depends on
 * LoggerPackage only transitively via callers; must be registered after it in
 * Container.php if push middleware/logging is added later.
 */
final class PushPackage implements PackageInterface
{
    public function build(ContainerBuilder $container): void {}

    public function getContainerExtension(): PushExtension
    {
        return new PushExtension();
    }
}
