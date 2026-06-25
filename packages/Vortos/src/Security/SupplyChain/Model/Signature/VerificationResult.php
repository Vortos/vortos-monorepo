<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Signature;

use Vortos\Security\SupplyChain\Model\SignatureMismatchException;

final readonly class VerificationResult
{
    /** @param list<string> $reasons */
    private function __construct(
        public bool $ok,
        public array $reasons = [],
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    /** @param list<string> $reasons */
    public static function failure(array $reasons): self
    {
        if ($reasons === []) {
            throw new \InvalidArgumentException('A failed VerificationResult must carry at least one reason.');
        }

        return new self(false, $reasons);
    }

    public function assertVerified(): void
    {
        if (!$this->ok) {
            throw new SignatureMismatchException(sprintf(
                'Signature verification failed: %s',
                implode('; ', $this->reasons),
            ));
        }
    }

    /** @return array{ok: bool, reasons: list<string>} */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'reasons' => $this->reasons,
        ];
    }
}
