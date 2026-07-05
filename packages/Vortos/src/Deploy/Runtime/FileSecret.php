<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * A file-shaped secret delivered to the blue/green color for the immutable-image deploy path (G8).
 *
 * Some secrets must be a file on disk (e.g. a tool that only reads an RS256 key from a path). The
 * zero-plaintext-to-disk posture of vortos-secrets otherwise forbids that. This spec declares such a
 * secret so the deploy one-shot can decrypt it from the age store to a **tmpfs (RAM) path** on the
 * target, then bind-mount it **read-only** into the color at {@see $containerPath}. Nothing is ever
 * written to a persistent filesystem; the age ciphertext store stays the only on-disk artifact.
 *
 * Prefer env-content secrets (e.g. vortos:auth:keys:generate --emit=env) — this channel exists for
 * the genuinely file-shaped cases that env content cannot cover.
 */
final readonly class FileSecret
{
    /** Host paths must live on a tmpfs (RAM) mount so plaintext never touches persistent storage. */
    public const ALLOWED_HOST_PREFIXES = ['/run/', '/dev/shm/'];

    public function __construct(
        /** Secret name (key) in the age store, e.g. 'jwt_private_key'. */
        public string $name,
        /** Absolute in-container mount path the app reads, e.g. '/run/secrets/jwt_private_key.pem'. */
        public string $containerPath,
        /** Absolute host tmpfs path the one-shot materialises to, e.g. '/run/vortos-secrets/jwt_private_key'. */
        public string $hostPath,
        /** File mode for the materialised plaintext; owner-read-only by default. */
        public int $mode = 0400,
    ) {
        if ($name === '' || preg_match('/\s/', $name) === 1) {
            throw new \InvalidArgumentException('FileSecret.name must be a non-empty token without whitespace.');
        }

        foreach (['containerPath' => $containerPath, 'hostPath' => $hostPath] as $field => $path) {
            if ($path === '' || !str_starts_with($path, '/') || preg_match('/\s/', $path) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'FileSecret.%s must be a non-empty absolute path without whitespace, got "%s".',
                    $field,
                    $path,
                ));
            }
        }

        $onTmpfs = false;
        foreach (self::ALLOWED_HOST_PREFIXES as $prefix) {
            if (str_starts_with($hostPath, $prefix)) {
                $onTmpfs = true;
                break;
            }
        }
        if (!$onTmpfs) {
            throw new \InvalidArgumentException(sprintf(
                'FileSecret.hostPath must be under a tmpfs mount (%s) so plaintext never persists to disk, got "%s".',
                implode(' or ', self::ALLOWED_HOST_PREFIXES),
                $hostPath,
            ));
        }

        if ($mode < 0 || $mode > 0777) {
            throw new \InvalidArgumentException(sprintf('FileSecret.mode must be a valid octal file mode, got %o.', $mode));
        }
    }

    /** The host directory that must exist (tmpfs) before materialisation, and be mounted into the one-shot. */
    public function hostDirectory(): string
    {
        return \dirname($this->hostPath);
    }

    /** The compose volume line mounting the materialised secret read-only into the color. */
    public function composeVolume(): string
    {
        return sprintf('%s:%s:ro', $this->hostPath, $this->containerPath);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'container_path' => $this->containerPath,
            'host_path' => $this->hostPath,
            'mode' => sprintf('0%o', $this->mode),
        ];
    }
}
