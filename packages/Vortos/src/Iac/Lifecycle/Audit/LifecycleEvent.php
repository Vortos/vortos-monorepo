<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Audit;

use Vortos\Iac\Lifecycle\LifecyclePhase;

final readonly class LifecycleEvent
{
    public function __construct(
        public LifecyclePhase $phase,
        public string $environment,
        public string $planDigest,
        public string $actor,
        public string $summary,
        public string $binaryVersion,
        public string $occurredAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase->value,
            'environment' => $this->environment,
            'plan_digest' => $this->planDigest,
            'actor' => $this->actor,
            'summary' => $this->summary,
            'binary_version' => $this->binaryVersion,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
