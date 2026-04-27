<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope;

use Symfony\Component\HttpFoundation\Request;
use Vortos\Authorization\Scope\Contract\ScopeResolverInterface;

/**
 * Registry of all scope resolvers keyed by scope name.
 * Populated at compile time by ScopeResolverCompilerPass.
 */
final class ScopeResolverRegistry
{
    /** @param array<string, ScopeResolverInterface> $resolvers */
    public function __construct(private array $resolvers = []) {}

    public function resolve(string $scopeName, Request $request): string
    {
        if (!isset($this->resolvers[$scopeName])) {
            throw new \InvalidArgumentException("No scope resolver registered for scope '{$scopeName}'.");
        }

        return $this->resolvers[$scopeName]->resolveScope($request);
    }

    public function has(string $scopeName): bool
    {
        return isset($this->resolvers[$scopeName]);
    }
}
