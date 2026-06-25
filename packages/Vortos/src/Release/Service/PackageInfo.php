<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

final readonly class PackageInfo
{
    public function __construct(
        public string $name,
        public string $path,
        public int $order,
    ) {}
}
