<?php

declare(strict_types=1);

namespace Vortos\Release\Git;

final class GitRemoteResolver
{
    /** @param array<string, string> $packageRemotes */
    public function __construct(
        private readonly string $defaultRemote = 'origin',
        private readonly array $packageRemotes = [],
    ) {}

    public function remoteFor(string $packageName): string
    {
        return $this->packageRemotes[$packageName] ?? $this->defaultRemote;
    }
}
