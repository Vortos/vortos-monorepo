<?php

declare(strict_types=1);

namespace Vortos\Docker\Service;

final class DockerPublishResult
{
    /**
     * @param string[] $copied
     * @param string[] $skipped
     * @param string[] $backedUp
     */
    public function __construct(
        public readonly array $copied = [],
        public readonly array $skipped = [],
        public readonly array $backedUp = [],
    ) {}
}
