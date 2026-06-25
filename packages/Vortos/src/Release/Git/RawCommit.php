<?php

declare(strict_types=1);

namespace Vortos\Release\Git;

final readonly class RawCommit
{
    public function __construct(
        public string $sha,
        public string $rawMessage,
        public \DateTimeImmutable $authoredAt,
    ) {}
}
