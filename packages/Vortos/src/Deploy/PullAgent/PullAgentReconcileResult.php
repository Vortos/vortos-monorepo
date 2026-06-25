<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

final readonly class PullAgentReconcileResult
{
    public function __construct(
        public bool $applied,
        public bool $alreadyCurrent,
        public ?string $detail = null,
        public ?int $appliedVersion = null,
    ) {}
}
