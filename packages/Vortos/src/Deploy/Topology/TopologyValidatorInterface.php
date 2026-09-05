<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

/**
 * Asks the tool that will actually run a topology whether it can.
 *
 * Separate from {@see ComposeTopologySync}'s own structural checks, which are deliberately
 * dependency-free so they work on any host and stay unit-testable. Those catch a truncated or
 * service-less file; they cannot catch a bad anchor, an unresolvable extends, or a schema violation.
 * Only the real parser knows those, and only some nodes carry it — so this is optional by
 * construction and its absence must never be the reason a sync is refused.
 *
 * An interface rather than a direct call because naming a provider outside the driver namespace is
 * how a framework stops being portable.
 */
interface TopologyValidatorInterface
{
    /**
     * @return string|null the reason to refuse the topology, or null when it is acceptable
     *                     (including when this validator cannot run here at all)
     */
    public function validate(string $path): ?string;
}
