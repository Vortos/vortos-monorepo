<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook;

/**
 * Read-only registry of all hook descriptors, keyed by hook type.
 *
 * Populated at compile time by HookDiscoveryCompilerPass via the
 * tekton.hooks container parameter. Immutable at runtime.
 *
 * Descriptors within each hook type are pre-sorted by priority descending.
 * HookRunner must not re-sort — order is guaranteed by the compiler pass.
 */
final class HookRegistry
{
    public function __construct(
        /** @var array<string, HookDescriptor[]> $hooks */
        private array $hooks
    ){
    }

    public function getHooks(string $hookType): array
    {
        return $this->hooks[$hookType] ?? [];
    }

    public function hasHooks(string $hookType):bool
    {
        return !empty($this->hooks[$hookType]);
    }

    public function all():array
    {
        return $this->hooks;
    }
}