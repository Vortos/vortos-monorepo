<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Verification;

/**
 * Resolves a git ref (a commit SHA or a tag) in a GitHub repository to its commit SHA.
 * Returns null when the ref does not exist — which is exactly how a bad pin (B7) is detected.
 */
interface ActionRefResolverInterface
{
    public function resolve(string $owner, string $repo, string $ref): ?string;
}
