<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

/** Violations of the topology as a whole, raised before any rule runs. */
enum ComposeTopologyViolationKind: string implements TopologyViolationKind
{
    /** The topology does not parse, or has no services mapping. */
    case Unreadable = 'unreadable_topology';
}
