<?php

declare(strict_types=1);

namespace Vortos\Http\Controller;

use Vortos\Http\Contract\ArgumentValueResolverInterface;
use Vortos\Http\Request;

final class ArgumentResolver
{
    /** @var array<string, \ReflectionFunction> */
    private array $reflectionCache = [];

    /**
     * @param ArgumentValueResolverInterface[] $resolvers Ordered by priority (highest first)
     */
    public function __construct(private readonly array $resolvers) {}

    public function resolve(Request $request, callable $callable): array
    {
        $reflection = $this->getReflection($callable);
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            foreach ($this->resolvers as $resolver) {
                if ($resolver->supports($request, $param)) {
                    $value = $resolver->resolve($request, $param);

                    // Variadic resolvers return an array of values to splat
                    if ($param->isVariadic()) {
                        array_push($args, ...(array) $value);
                    } else {
                        $args[] = $value;
                    }

                    continue 2;
                }
            }

            throw new \RuntimeException(sprintf(
                'No resolver found for parameter "$%s" of controller "%s". '
                . 'Did you forget a type hint, a route param, or a #[AsTaggedItem] resolver?',
                $param->getName(),
                $this->describeCallable($callable),
            ));
        }

        return $args;
    }

    private function getReflection(callable $callable): \ReflectionFunction
    {
        $key = $this->cacheKey($callable);

        if (!isset($this->reflectionCache[$key])) {
            $this->reflectionCache[$key] = new \ReflectionFunction(\Closure::fromCallable($callable));
        }

        return $this->reflectionCache[$key];
    }

    private function cacheKey(callable $callable): string
    {
        if (is_array($callable)) {
            $class = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];
            return $class . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return $callable::class . '::__invoke';
        }

        return (string) $callable;
    }

    private function describeCallable(callable $callable): string
    {
        if (is_array($callable)) {
            $class = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];
            return $class . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return $callable::class . '::__invoke';
        }

        return (string) $callable;
    }
}
