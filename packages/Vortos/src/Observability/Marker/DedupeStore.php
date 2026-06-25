<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker;

interface DedupeStore
{
    public function seen(string $key): bool;

    public function remember(string $key): void;
}
