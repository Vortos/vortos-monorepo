<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Verification;

/**
 * How an action's `runs.using` runtime stands with GitHub's runner-deprecation lifecycle.
 *
 * - {@see Current}: a supported runtime (node24, docker, composite, …).
 * - {@see Deprecated}: still executes (GitHub auto-upgrades it) but warns — e.g. node20.
 * - {@see Removed}: no longer executes — e.g. node16/node12. The verifier fails closed on this.
 * - {@see Unknown}: could not be determined (no resolver, missing metadata, network failure).
 */
enum ActionRuntimeStatus: string
{
    case Current = 'current';
    case Deprecated = 'deprecated';
    case Removed = 'removed';
    case Unknown = 'unknown';
}
