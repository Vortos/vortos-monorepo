<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Api;

final readonly class RolloutStatus
{
    public function __construct(
        public bool $ready,
        public int $readyReplicas,
        public int $desiredReplicas,
        public int $updatedReplicas,
        public string $imageDigest = '',
        public string $message = '',
    ) {}

    public function isComplete(): bool
    {
        return $this->ready
            && $this->readyReplicas >= $this->desiredReplicas
            && $this->updatedReplicas >= $this->desiredReplicas;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'ready_replicas' => $this->readyReplicas,
            'desired_replicas' => $this->desiredReplicas,
            'updated_replicas' => $this->updatedReplicas,
            'image_digest' => $this->imageDigest,
            'message' => $this->message,
        ];
    }
}
