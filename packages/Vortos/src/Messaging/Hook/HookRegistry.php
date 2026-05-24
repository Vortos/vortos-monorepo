<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook;

/**
 * Read-only registry of all hook descriptors, keyed by hook type.
 *
 * Populated at compile time by HookDiscoveryCompilerPass via the
 * vortos.hooks container parameter. Immutable at runtime.
 *
 * Descriptors within each hook type are pre-sorted by priority descending.
 * HookRunner must not re-sort — order is guaranteed by the compiler pass.
 */
final class HookRegistry
{
    /** @var array<string, HookDescriptor[]> */
    private array $hooks;

    public function __construct(array $hooks)
    {
        $this->hooks = [];
        foreach ($hooks as $hookType => $descriptors) {
            foreach ($descriptors as $descriptor) {
                $this->hooks[$hookType][] = new HookDescriptor(
                    hookType:       $descriptor['hookType'],
                    serviceId:      $descriptor['serviceId'],
                    eventFilter:    $descriptor['eventFilter'] ?? null,
                    consumerFilter: $descriptor['consumerFilter'] ?? null,
                    priority:       $descriptor['priority'] ?? 0,
                    onFailureOnly:  $descriptor['onFailureOnly'] ?? false,
                    on:             array_map(
                        static fn(mixed $v) => $v instanceof HandlerOutcome ? $v : HandlerOutcome::from((string) $v),
                        $descriptor['on'] ?? [],
                    ),
                );
            }
        }
    }

    public function getHooks(string $hookType): array
    {
        return $this->hooks[$hookType] ?? [];
    }

    public function hasHooks(string $hookType): bool
    {
        return !empty($this->hooks[$hookType]);
    }

    public function all(): array
    {
        return $this->hooks;
    }
}