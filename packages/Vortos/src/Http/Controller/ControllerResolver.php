<?php

declare(strict_types=1);

namespace Vortos\Http\Controller;

use Psr\Container\ContainerInterface;
use Vortos\Http\Request;

final class ControllerResolver
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function resolve(Request $request): callable
    {
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            throw new \LogicException('No _controller attribute found on request. Was Router::match() called?');
        }

        if (is_callable($controller)) {
            return $controller;
        }

        if (is_string($controller)) {
            if (str_contains($controller, '::')) {
                [$class, $method] = explode('::', $controller, 2);
                $instance = $this->container->get($class);
                return [$instance, $method];
            }

            $instance = $this->container->get($controller);

            if (!is_callable($instance)) {
                throw new \InvalidArgumentException(sprintf('Controller "%s" is not callable (missing __invoke?).', $controller));
            }

            return $instance;
        }

        throw new \InvalidArgumentException(sprintf('Invalid controller type "%s".', get_debug_type($controller)));
    }
}
