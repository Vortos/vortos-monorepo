<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore;

use InvalidArgumentException;

final readonly class RestoreRequest
{
    public function __construct(
        public string $destinationDsn,
        public bool $assertSchemaCompatible = false,
        /** @var array<string, mixed> */
        public array $options = [],
    ) {
        if ($destinationDsn === '') {
            throw new InvalidArgumentException('Restore destination DSN must be non-empty.');
        }
    }
}
