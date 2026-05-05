<?php

declare(strict_types=1);

namespace Vortos\Authorization\Contract;

interface EmergencyDenyListInterface
{
    public function deny(string $userId): void;

    public function allow(string $userId): void;

    public function isDenied(string $userId): bool;
}
