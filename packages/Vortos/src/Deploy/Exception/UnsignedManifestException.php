<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class UnsignedManifestException extends DeployException
{
    public static function create(): self
    {
        return new self('Manifest has no signature — unsigned manifests are rejected.');
    }
}
