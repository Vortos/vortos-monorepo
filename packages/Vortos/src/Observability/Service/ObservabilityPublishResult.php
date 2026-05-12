<?php

declare(strict_types=1);

namespace Vortos\Observability\Service;

final readonly class ObservabilityPublishResult
{
    /**
     * @param list<string> $published
     * @param list<string> $skipped
     */
    public function __construct(
        public array $published = [],
        public array $skipped = [],
    ) {}
}

