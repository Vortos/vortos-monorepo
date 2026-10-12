<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

final readonly class RunningContainer
{
    public function __construct(
        public string $id,
        public string $name,
        /** The image reference the container was created from — a digest reference when pinned. */
        public string $image,
    ) {}
}
