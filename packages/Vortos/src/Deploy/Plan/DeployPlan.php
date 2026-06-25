<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

final readonly class DeployPlan
{
    public PlanHash $planHash;

    /**
     * @param list<DeployPhase> $phases
     */
    public function __construct(
        public array $phases,
        public string $definitionHash,
        public ?string $signature = null,
        public ?string $signedBy = null,
    ) {
        $this->planHash = PlanHash::fromPlanJson($this->toCanonicalJson());
    }

    public function isEmpty(): bool
    {
        return $this->phases === [];
    }

    public function phaseCount(): int
    {
        return \count($this->phases);
    }

    public function hasPhase(PhaseKind $kind): bool
    {
        foreach ($this->phases as $phase) {
            if ($phase->kind === $kind) {
                return true;
            }
        }

        return false;
    }

    public function toCanonicalJson(): string
    {
        $data = [
            'definition_hash' => $this->definitionHash,
            'phases' => array_map(
                static fn (DeployPhase $p): array => $p->toArray(),
                $this->phases,
            ),
        ];

        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_hash' => $this->planHash->toString(),
            'definition_hash' => $this->definitionHash,
            'phases' => array_map(
                static fn (DeployPhase $p): array => $p->toArray(),
                $this->phases,
            ),
            'signature' => $this->signature,
            'signed_by' => $this->signedBy,
        ];
    }
}
