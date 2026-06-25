<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final class InvalidVersionException extends \InvalidArgumentException
{
    public static function fromString(string $version): self
    {
        return new self(sprintf(
            'Cannot parse "%s" as a semantic version. Expected format: [v]MAJOR.MINOR.PATCH[-prerelease][+build].',
            $version,
        ));
    }
}
