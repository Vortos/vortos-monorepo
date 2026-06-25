<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

final class DesiredStateManifestFactory
{
    public function create(
        string $env,
        string $releaseVersion,
        string $imageDigest,
        string $activeColor,
        string $composeProjection,
        string $schemaFingerprint,
        int $version,
    ): DesiredStateManifest {
        return new DesiredStateManifest(
            env: $env,
            releaseVersion: $releaseVersion,
            imageDigest: $imageDigest,
            activeColor: $activeColor,
            composeProjection: $composeProjection,
            schemaFingerprint: $schemaFingerprint,
            issuedAt: new \DateTimeImmutable(),
            version: $version,
            nonce: bin2hex(random_bytes(16)),
        );
    }
}
