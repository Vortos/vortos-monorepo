<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

/**
 * Implemented by each rule's backed enum of violation kinds, so a violation is always a typed,
 * rule-owned kind rather than a free-form string.
 */
interface TopologyViolationKind extends \BackedEnum
{
}
