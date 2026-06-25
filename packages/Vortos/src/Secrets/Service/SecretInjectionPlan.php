<?php

declare(strict_types=1);

namespace Vortos\Secrets\Service;

/**
 * An ordered `ENV_VAR ⇒ SecretValue` map, ready to be pushed into a process'
 * environment / tmpfs by a deploy driver. {@see materialize()} is deliberately the
 * only method that reveals — building the plan never does — so every plaintext
 * exit from this layer is a single, auditable call site.
 */
final readonly class SecretInjectionPlan
{
    /** @param list<SecretInjectionEntry> $entries */
    public function __construct(public array $entries) {}

    /** @return list<string> */
    public function envVars(): array
    {
        return array_map(static fn (SecretInjectionEntry $e): string => $e->envVar, $this->entries);
    }

    /**
     * The only plaintext exit from a {@see SecretInjectionPlan}. Every call site is
     * a deliberate, auditable boundary crossing — mirrors {@see \Vortos\Secrets\Value\SecretValue::reveal()}.
     *
     * @return array<string, string>
     */
    public function materialize(): array
    {
        $map = [];
        foreach ($this->entries as $entry) {
            $map[$entry->envVar] = $entry->value->reveal();
        }

        return $map;
    }
}
