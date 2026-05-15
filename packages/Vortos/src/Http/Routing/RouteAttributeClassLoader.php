<?php

declare(strict_types=1);

namespace Vortos\Http\Routing;

use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

class RouteAttributeClassLoader extends AttributeClassLoader
{

    protected function configureRoute(Route $route, \ReflectionClass $class, \ReflectionMethod $method, object $attr): void 
    {
        $route->setDefault('_controller', $class->getName(). '::' . $method->getName());
    }
}
