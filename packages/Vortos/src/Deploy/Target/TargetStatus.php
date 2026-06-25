<?php

declare(strict_types=1);

namespace Vortos\Deploy\Target;

final readonly class TargetStatus
{
    public function __construct(
        public ActiveColor $color,
        public string $imageDigest,
        public string $healthStatus,
        public \DateTimeImmutable $checkedAt,
    ) {
        if ($imageDigest !== '' && !preg_match('/^sha256:[a-f0-9]{64}$/', $imageDigest)) {
            throw new \InvalidArgumentException(sprintf(
                'Image digest must match sha256:<64 hex> or be empty, got "%s".',
                $imageDigest,
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'color' => $this->color->value,
            'image_digest' => $this->imageDigest,
            'health_status' => $this->healthStatus,
            'checked_at' => $this->checkedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
