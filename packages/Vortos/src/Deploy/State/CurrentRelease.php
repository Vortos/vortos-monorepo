<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

use Vortos\Deploy\Target\ActiveColor;

final readonly class CurrentRelease
{
    public function __construct(
        public string $env,
        public ActiveColor $activeColor,
        public string $imageDigest,
        public string $buildId,
        public string $planHash,
        public \DateTimeImmutable $recordedAt,
        public int $generation,
    ) {
        if ($env === '') {
            throw new \InvalidArgumentException('CurrentRelease env must not be empty.');
        }

        if ($generation < 0) {
            throw new \InvalidArgumentException(sprintf('CurrentRelease generation must be >= 0, got %d.', $generation));
        }
    }

    public function withColor(ActiveColor $color): self
    {
        return new self(
            env: $this->env,
            activeColor: $color,
            imageDigest: $this->imageDigest,
            buildId: $this->buildId,
            planHash: $this->planHash,
            recordedAt: $this->recordedAt,
            generation: $this->generation,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'env' => $this->env,
            'active_color' => $this->activeColor->value,
            'image_digest' => $this->imageDigest,
            'build_id' => $this->buildId,
            'plan_hash' => $this->planHash,
            'recorded_at' => $this->recordedAt->format(\DateTimeInterface::ATOM),
            'generation' => $this->generation,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            env: (string) $data['env'],
            activeColor: ActiveColor::from((string) $data['active_color']),
            imageDigest: (string) $data['image_digest'],
            buildId: (string) $data['build_id'],
            planHash: (string) $data['plan_hash'],
            recordedAt: new \DateTimeImmutable((string) $data['recorded_at']),
            generation: (int) $data['generation'],
        );
    }
}
