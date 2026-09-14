<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

/**
 * One invariant a compose topology must hold before it may reach a host.
 *
 * Rules are pure: they read the parsed {@see ComposeTopology} and the declarations they were built from,
 * never the filesystem. Compose them in {@see TopologyPolicy::forDefinition()}.
 */
interface TopologyRuleInterface
{
    /** Stable snake_case identifier, reported with every violation. */
    public function name(): string;

    /** @return list<TopologyViolation> */
    public function evaluate(ComposeTopology $topology): array;
}
