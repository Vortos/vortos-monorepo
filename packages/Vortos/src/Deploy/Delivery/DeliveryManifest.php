<?php

declare(strict_types=1);

namespace Vortos\Deploy\Delivery;

use Vortos\Deploy\Definition\DeliveryDefaults;

/**
 * The declared set of files the deploy ships to the VPS deploy dir (G3): the prod env file, the
 * prod compose, the mounted config trees and the age-encrypted secrets store. Previously nothing
 * drove the SSH transport copy path; this makes what is delivered a first-class, validated,
 * fail-closed part of the deploy.
 */
final class DeliveryManifest
{
    /** @param list<DeliveryArtifact> $artifacts */
    public function __construct(public readonly array $artifacts)
    {
        if ($artifacts === []) {
            throw new \InvalidArgumentException('A delivery manifest must declare at least one artifact.');
        }
    }

    /**
     * The default artifact set for a Vortos deploy, resolved against the project dir. The config
     * trees are expanded to their concrete files. Optional trees that do not exist are simply skipped.
     *
     * The secrets store is shipped 0640, not 0600 (B15): the deploy-in-image one-shots read it as the
     * image's runtime uid, which differs from the delivering (deploy) uid, so an owner-only 0600 file
     * is unreadable by the container. 0640 grants owner+group read; the remote run adds the store's
     * group to the container via docker run --group-add, so only that group — never the world — can
     * read it. The bytes are age CIPHERTEXT (the KEK arrives via env, never on disk), so group-read
     * leaks no plaintext. .env.prod stays 0600: the docker CLI reads it as the deploy uid via
     * --env-file, so the container uid never needs to.
     */
    public static function default(string $projectDir): self
    {
        $projectDir = rtrim($projectDir, '/');
        $artifacts = [
            new DeliveryArtifact($projectDir . '/' . DeliveryDefaults::ENV_FILE, DeliveryDefaults::ENV_FILE, '0600', required: true),
            new DeliveryArtifact($projectDir . '/' . DeliveryDefaults::COMPOSE_FILE, DeliveryDefaults::COMPOSE_FILE, '0644', required: true),
            new DeliveryArtifact($projectDir . '/' . DeliveryDefaults::SECRETS_FILE, DeliveryDefaults::SECRETS_FILE, '0640', required: false),
        ];

        foreach (DeliveryDefaults::CONFIG_TREES as $tree) {
            foreach (self::expandTree($projectDir, $tree) as $artifact) {
                $artifacts[] = $artifact;
            }
        }

        return new self($artifacts);
    }

    /** @return list<DeliveryArtifact> */
    public function requiredMissing(): array
    {
        return array_values(array_filter(
            $this->artifacts,
            static fn (DeliveryArtifact $a): bool => $a->required && !$a->existsLocally(),
        ));
    }

    public function assertDeliverable(): void
    {
        $missing = $this->requiredMissing();
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Cannot deliver: required artifact(s) missing locally: %s',
                implode(', ', array_map(static fn (DeliveryArtifact $a): string => $a->localPath, $missing)),
            ));
        }
    }

    /**
     * Artifacts that actually exist locally (skips absent optional trees), in deterministic order.
     *
     * @return list<DeliveryArtifact>
     */
    public function present(): array
    {
        $present = array_values(array_filter($this->artifacts, static fn (DeliveryArtifact $a): bool => $a->existsLocally()));
        usort($present, static fn (DeliveryArtifact $a, DeliveryArtifact $b): int => strcmp($a->remoteRelativePath, $b->remoteRelativePath));

        return $present;
    }

    /** @return list<DeliveryArtifact> */
    private static function expandTree(string $projectDir, string $tree): array
    {
        $base = $projectDir . '/' . $tree;
        if (!is_dir($base)) {
            return [];
        }

        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = $tree . '/' . ltrim(substr($file->getPathname(), strlen($base) + 1), '/');
            $out[] = new DeliveryArtifact($file->getPathname(), $relative, '0644', required: false);
        }

        return $out;
    }
}
