<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use Psr\Container\ContainerInterface;
use Vortos\OpsKit\Driver\TaggedDriverRegistry;

/**
 * The demo concern's typed registry — exactly the 3-line shape every real concern
 * (deploy, health, …) will write.
 */
final class GreeterRegistry extends TaggedDriverRegistry
{
    public function __construct(ContainerInterface $drivers)
    {
        parent::__construct('greeter', $drivers);
    }

    public function greeter(string $key): GreeterInterface
    {
        $greeter = $this->get($key);
        \assert($greeter instanceof GreeterInterface);

        return $greeter;
    }
}
