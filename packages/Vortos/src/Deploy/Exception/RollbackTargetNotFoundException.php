<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

/**
 * Thrown when a rollback cannot resolve a target build: an explicit '--to' build id
 * that does not exist, or no previous known-good build for the environment. The
 * rollback refuses rather than guessing a target.
 */
final class RollbackTargetNotFoundException extends DeployException
{
    public static function unknownBuild(string $buildId, string $env): self
    {
        return new self(sprintf(
            'Rollback target build "%s" was not found for environment "%s".',
            $buildId,
            $env,
        ));
    }

    public static function noPrevious(string $env): self
    {
        return new self(sprintf(
            'No previous known-good build exists for environment "%s"; nothing to roll back to. Specify --to=<buildId>.',
            $env,
        ));
    }
}
