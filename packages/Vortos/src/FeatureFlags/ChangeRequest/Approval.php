<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

final readonly class Approval
{
    public function __construct(
        public string $actorId,
        public string $reason,
        public \DateTimeImmutable $at,
    ) {}

    public function toArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'reason'  => $this->reason,
            'at'      => $this->at->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            actorId: (string) $data['actorId'],
            reason:  (string) $data['reason'],
            at:      new \DateTimeImmutable((string) $data['at']),
        );
    }
}
