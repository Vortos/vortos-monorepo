<?php

declare(strict_types=1);

namespace Vortos\Secrets\Preflight;

use Vortos\Secrets\Value\SecretKey;

/**
 * One declared requirement: "this app needs a secret named X."
 */
final readonly class SecretReference
{
    public function __construct(
        public SecretKey $key,
        public bool $required = true,
        public string $description = '',
    ) {}
}
