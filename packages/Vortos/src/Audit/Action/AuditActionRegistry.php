<?php

declare(strict_types=1);

namespace Vortos\Audit\Action;

/**
 * Immutable aggregate of every declared audit action. Built once from all tagged
 * providers; used to validate recorded actions against the controlled vocabulary and
 * to resolve an action's default sensitivity.
 */
final class AuditActionRegistry
{
    /** @var array<string, RegisteredAction> */
    private array $byKey = [];

    /**
     * @param iterable<AuditActionProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            foreach ($provider->actions() as $action) {
                if (isset($this->byKey[$action->key])) {
                    throw new \LogicException(
                        "Duplicate audit action '{$action->key}' declared by " . $provider::class,
                    );
                }
                $this->byKey[$action->key] = $action;
            }
        }
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): ?RegisteredAction
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @return array<string, RegisteredAction>
     */
    public function all(): array
    {
        return $this->byKey;
    }
}
