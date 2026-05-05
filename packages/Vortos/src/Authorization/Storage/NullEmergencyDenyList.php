<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Vortos\Authorization\Contract\EmergencyDenyListInterface;

final class NullEmergencyDenyList implements EmergencyDenyListInterface
{
    public function deny(string $userId): void
    {
    }

    public function allow(string $userId): void
    {
    }

    public function isDenied(string $userId): bool
    {
        return false;
    }
}
