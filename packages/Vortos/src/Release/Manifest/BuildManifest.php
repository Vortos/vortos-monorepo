<?php

declare(strict_types=1);

namespace Vortos\Release\Manifest;

use Vortos\Release\Schema\SchemaFingerprint;

final readonly class BuildManifest
{
    private const IMAGE_DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/';
    private const GIT_SHA_PATTERN = '/^[a-f0-9]{7,40}$/';

    public function __construct(
        public string $buildId,
        public string $gitSha,
        public string $imageDigest,
        public Arch $targetArch,
        public string $environment,
        public SchemaFingerprint $schemaFingerprint,
        public \DateTimeImmutable $createdAt,
        public ?Provenance $provenance = null,
    ) {
        if ($buildId === '') {
            throw new \InvalidArgumentException('Build ID must not be empty.');
        }

        if (!preg_match(self::GIT_SHA_PATTERN, $gitSha)) {
            throw new \InvalidArgumentException(sprintf(
                'Git SHA must be 7-40 lowercase hex chars, got "%s".',
                $gitSha,
            ));
        }

        if (!preg_match(self::IMAGE_DIGEST_PATTERN, $imageDigest)) {
            throw new \InvalidArgumentException(sprintf(
                'Image digest must match sha256:<64 hex>, got "%s".',
                $imageDigest,
            ));
        }

        if ($environment === '') {
            throw new \InvalidArgumentException('Environment must not be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'build_id' => $this->buildId,
            'git_sha' => $this->gitSha,
            'image_digest' => $this->imageDigest,
            'target_arch' => $this->targetArch->value,
            'environment' => $this->environment,
            'schema_fingerprint' => $this->schemaFingerprint->toArray(),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'provenance' => $this->provenance?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            buildId: $data['build_id'],
            gitSha: $data['git_sha'],
            imageDigest: $data['image_digest'],
            targetArch: Arch::from($data['target_arch']),
            environment: $data['environment'],
            schemaFingerprint: SchemaFingerprint::fromArray($data['schema_fingerprint']),
            createdAt: new \DateTimeImmutable($data['created_at']),
            provenance: isset($data['provenance']) ? Provenance::fromArray($data['provenance']) : null,
        );
    }
}
