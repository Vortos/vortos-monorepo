<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Attestation;

final readonly class CveGateDecision
{
    /**
     * @param list<string> $reasons
     * @param list<string> $offendingCves
     */
    private function __construct(
        public bool $pass,
        public array $reasons,
        public array $offendingCves,
    ) {}

    public static function passed(): self
    {
        return new self(true, [], []);
    }

    /**
     * @param list<string> $reasons
     * @param list<string> $offendingCves
     */
    public static function failed(array $reasons, array $offendingCves): self
    {
        if ($reasons === []) {
            throw new \InvalidArgumentException('A failed CveGateDecision must carry at least one reason.');
        }

        return new self(false, $reasons, $offendingCves);
    }

    /** @return array{pass: bool, reasons: list<string>, offending_cves: list<string>} */
    public function toArray(): array
    {
        return [
            'pass' => $this->pass,
            'reasons' => $this->reasons,
            'offending_cves' => $this->offendingCves,
        ];
    }
}
