<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

use Vortos\Pipeline\Definition\PipelineDefinition;

/**
 * Every invariant a compose topology must hold before it may reach a host, evaluated over one parse.
 *
 * Run by `pipeline:topology:check`, which the generator emits into the gating tests job whenever the
 * topology is synced to the host — so a violation stops the release before an image is built.
 *
 * ADDING A RULE: implement {@see TopologyRuleInterface} (with its own {@see TopologyViolationKind} enum),
 * build it from the definition, and append it in {@see forDefinition()}. Rules must be pure.
 */
final class TopologyPolicy
{
    /** @param list<TopologyRuleInterface> $rules */
    public function __construct(
        private readonly string $hostDir,
        private readonly array $rules,
    ) {
        if ($rules === []) {
            throw new \InvalidArgumentException('A topology policy with no rules would pass every topology.');
        }
    }

    public static function forDefinition(PipelineDefinition $definition): self
    {
        return new self($definition->remoteDeployDir, [
            EnvFileScopeRule::forDefinition($definition),
        ]);
    }

    /** @return list<TopologyViolation> */
    public function evaluate(string $composeYaml): array
    {
        try {
            $topology = ComposeTopology::parse($composeYaml, $this->hostDir);
        } catch (UnreadableTopologyException $e) {
            return [new TopologyViolation('topology', ComposeTopologyViolationKind::Unreadable, '', '', $e->getMessage())];
        }

        $violations = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($topology) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function ruleNames(): array
    {
        return array_map(static fn (TopologyRuleInterface $r): string => $r->name(), $this->rules);
    }
}
