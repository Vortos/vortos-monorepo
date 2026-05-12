<?php

declare(strict_types=1);

namespace Vortos\Observability\Service;

final readonly class ObservabilityStack
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $files,
    ) {}
}

