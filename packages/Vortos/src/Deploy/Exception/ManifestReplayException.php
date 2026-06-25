<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class ManifestReplayException extends DeployException
{
    public static function create(string $nonce): self
    {
        return new self(sprintf('Manifest nonce "%s" has already been seen — replay rejected.', $nonce));
    }
}
