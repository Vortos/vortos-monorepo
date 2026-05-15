<?php

declare(strict_types=1);

namespace Vortos\Domain\Command;

abstract readonly class AbstractCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
