<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\OpsKit\Driver\DriverInterface;

interface RestoreTargetInterface extends DriverInterface
{
    /**
     * Restore plaintext chunks into the destination.
     *
     * @param iterable<string> $chunks decrypted plaintext chunks (bounded memory)
     */
    public function restore(iterable $chunks, RestoreRequest $request): void;

    public function engine(): DatabaseEngine;
}
