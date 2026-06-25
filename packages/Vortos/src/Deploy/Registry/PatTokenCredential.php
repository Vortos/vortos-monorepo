<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

use Vortos\Secrets\Value\SecretValue;

final class PatTokenCredential extends RegistryCredential
{
    public function __construct(
        public readonly string $username,
        public readonly SecretValue $token,
    ) {
        if ($username === '') {
            throw new \InvalidArgumentException('GHCR username must be non-empty.');
        }
    }
}
