<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

/**
 * A reference to a running drill container. `$host` is the name other containers on the shared
 * network resolve it by, so the drill connects over Docker's embedded DNS and never needs a published
 * port on the host.
 */
final readonly class ContainerHandle
{
    public function __construct(
        public string $id,
        public string $name,
        public string $host,
    ) {}
}
