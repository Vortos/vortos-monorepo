<?php

declare(strict_types=1);

namespace Vortos\Release\Manifest;

final class ManifestAlreadyExistsException extends \RuntimeException
{
    public static function forBuildId(string $buildId): self
    {
        return new self(sprintf('Build manifest with ID "%s" already exists and cannot be overwritten.', $buildId));
    }
}
