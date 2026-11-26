<?php

declare(strict_types=1);

namespace Vortos\Backup\Immutability;

final readonly class ImmutabilityAudit
{
    /**
     * @param list<string>         $problems each one a reason to page
     * @param array<string, mixed> $facts    what the provider reported
     */
    public function __construct(
        public array $problems,
        public array $facts,
    ) {}
}
