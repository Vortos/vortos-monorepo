<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

final readonly class DesiredStateManifest
{
    public function __construct(
        public string $env,
        public string $releaseVersion,
        public string $imageDigest,
        public string $activeColor,
        public string $composeProjection,
        public string $schemaFingerprint,
        public \DateTimeImmutable $issuedAt,
        public int $version,
        public string $nonce,
    ) {
        if ($env === '') {
            throw new \InvalidArgumentException('Manifest env must not be empty.');
        }

        if (!preg_match('/^@?sha256:[a-f0-9]{64}$/', $imageDigest)) {
            throw new \InvalidArgumentException(sprintf('Invalid image digest: %s', $imageDigest));
        }

        if ($version < 1) {
            throw new \InvalidArgumentException(sprintf('Manifest version must be >= 1, got %d.', $version));
        }

        if ($nonce === '') {
            throw new \InvalidArgumentException('Manifest nonce must not be empty.');
        }
    }

    public function toCanonicalBytes(): string
    {
        $data = [
            'active_color' => $this->activeColor,
            'compose_projection' => $this->composeProjection,
            'env' => $this->env,
            'image_digest' => $this->imageDigest,
            'issued_at' => $this->issuedAt->format(\DateTimeInterface::ATOM),
            'nonce' => $this->nonce,
            'release_version' => $this->releaseVersion,
            'schema_fingerprint' => $this->schemaFingerprint,
            'version' => $this->version,
        ];

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $json;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'env' => $this->env,
            'release_version' => $this->releaseVersion,
            'image_digest' => $this->imageDigest,
            'active_color' => $this->activeColor,
            'compose_projection' => $this->composeProjection,
            'schema_fingerprint' => $this->schemaFingerprint,
            'issued_at' => $this->issuedAt->format(\DateTimeInterface::ATOM),
            'version' => $this->version,
            'nonce' => $this->nonce,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            env: (string) $data['env'],
            releaseVersion: (string) $data['release_version'],
            imageDigest: (string) $data['image_digest'],
            activeColor: (string) $data['active_color'],
            composeProjection: (string) $data['compose_projection'],
            schemaFingerprint: (string) $data['schema_fingerprint'],
            issuedAt: new \DateTimeImmutable((string) $data['issued_at']),
            version: (int) $data['version'],
            nonce: (string) $data['nonce'],
        );
    }
}
