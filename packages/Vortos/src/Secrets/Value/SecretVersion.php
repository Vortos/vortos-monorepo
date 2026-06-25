<?php

declare(strict_types=1);

namespace Vortos\Secrets\Value;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One version of a secret. Carries no plaintext — only identity + lifecycle
 * metadata. The plaintext for a version is obtained separately via the
 * {@see \Vortos\Secrets\Provider\SecretsProviderInterface}.
 */
final readonly class SecretVersion
{
    public function __construct(
        public string $versionId,
        public DateTimeImmutable $createdAt,
        public SecretVersionState $state,
    ) {
        if ($versionId === '') {
            throw new InvalidArgumentException('SecretVersion::$versionId must not be empty.');
        }
    }

    public function withState(SecretVersionState $state): self
    {
        return new self($this->versionId, $this->createdAt, $state);
    }

    public function isValid(): bool
    {
        return $this->state->isValid();
    }

    /** @return array{versionId: string, createdAt: string, state: string} */
    public function toArray(): array
    {
        return [
            'versionId' => $this->versionId,
            'createdAt' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'state' => $this->state->value,
        ];
    }

    /** @param array{versionId: string, createdAt: string, state: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['versionId'],
            new DateTimeImmutable($data['createdAt']),
            SecretVersionState::from($data['state']),
        );
    }
}
