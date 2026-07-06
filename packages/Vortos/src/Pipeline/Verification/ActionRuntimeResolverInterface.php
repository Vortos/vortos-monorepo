<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Verification;

/**
 * Resolves the runner an action executes on — the `runs.using` value from its `action.yml`
 * (e.g. `node20`, `node24`, `docker`, `composite`) at a given ref. Returns null when it cannot be
 * determined (missing metadata, network failure), which callers treat as "cannot judge", not "ok".
 */
interface ActionRuntimeResolverInterface
{
    public function runtime(string $owner, string $repo, string $ref): ?string;
}
