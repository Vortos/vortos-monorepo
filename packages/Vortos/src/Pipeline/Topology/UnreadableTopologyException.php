<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

/** The topology cannot be parsed into services, so no rule can prove anything about it. */
final class UnreadableTopologyException extends \RuntimeException
{
}
