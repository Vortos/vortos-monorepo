<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Oci;

use Vortos\Deploy\Registry\ImageReference;

/**
 * Shared OCI operations (push, pull, tag, digest) for all per-registry drivers.
 *
 * Using classes must declare:
 *   private CommandRunnerInterface $runner
 *   private ImageSignerInterface   $signer
 * and implement:
 *   private function authenticate(): void
 *   private function redactTokens(): list<string>
 */
trait OciOps
{
    abstract private function authenticate(): void;

    /** @return list<string> */
    abstract private function redactTokens(): array;

    public function push(ImageReference $image): ImageReference
    {
        $this->authenticate();

        $argv = ['docker', 'push', $image->toString()];
        $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        $result->throwOnFailure('docker push');

        $digest = $this->digestFor($image);
        $pushed = $image->withDigest($digest);

        $this->signer->sign($pushed);

        return $pushed;
    }

    public function pull(ImageReference $image): void
    {
        if (!$image->isDigestPinned()) {
            throw new \InvalidArgumentException('Pull requires a digest-pinned image reference.');
        }

        // B18: in the deploy-in-image topology the host has already pulled the digest-pinned image
        // (the remote deploy script runs "docker pull" before the one-shot). Re-pulling from inside
        // the credential-less one-shot would re-authenticate against the registry and fail
        // ("pull access denied … requires docker login") for a byte-identical image that is already
        // present. Short-circuit when the exact digest is local — no auth, no network.
        if ($this->localDaemonHasImage($image)) {
            return;
        }

        $this->authenticate();

        $argv = ['docker', 'pull', $image->toString()];
        $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        $result->throwOnFailure('docker pull');
    }

    public function tag(ImageReference $image, string $tag): ImageReference
    {
        $tagged = $image->withTag($tag);
        $argv = ['docker', 'tag', $image->toString(), $tagged->toString()];
        $result = $this->runner->run($argv);
        $result->throwOnFailure('docker tag');

        return $tagged;
    }

    public function digestFor(ImageReference $image): string
    {
        // B17: daemon-first. In the deploy-in-image topology the image is already present on the host
        // daemon (reached over the least-privilege docker-socket-proxy) but the one-shot container has
        // neither registry credentials nor crane/skopeo/buildx. A local daemon lookup needs neither a
        // tool nor auth, so try it before any registry-direct resolver.
        $digest = $this->tryLocalDaemonDigest($image);
        if ($digest !== null) {
            return $digest;
        }

        $digest = $this->tryDaemonlessDigest($image);
        if ($digest !== null) {
            return $digest;
        }

        $argv = ['docker', 'buildx', 'imagetools', 'inspect', '--raw', $image->toString()];
        $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        $result->throwOnFailure('digest inspection');

        return $this->extractDigestFromInspect($result->stdout, $image);
    }

    /**
     * Resolves the image digest from the local Docker daemon via
     * docker image inspect --format '{{json .RepoDigests}}'. Returns the sha256:… for the entry
     * whose repository matches {@see ImageReference::$repository}, or null when the image is not
     * present locally / the daemon is unreachable / no repo digest is recorded yet.
     */
    private function tryLocalDaemonDigest(ImageReference $image): ?string
    {
        $repoDigests = $this->inspectRepoDigests($image);
        if ($repoDigests === null) {
            return null;
        }

        foreach ($repoDigests as $repoDigest) {
            if (!is_string($repoDigest)) {
                continue;
            }

            $at = strrpos($repoDigest, '@');
            if ($at === false) {
                continue;
            }

            if (substr($repoDigest, 0, $at) !== $image->repository) {
                continue;
            }

            $digest = substr($repoDigest, $at + 1);
            if (preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1) {
                return $digest;
            }
        }

        return null;
    }

    /**
     * True when the exact image is already present on the local daemon. For a digest-pinned
     * reference the specific repo@sha256:… must be listed in RepoDigests; otherwise any recorded
     * repo digest counts as present.
     */
    private function localDaemonHasImage(ImageReference $image): bool
    {
        $repoDigests = $this->inspectRepoDigests($image);
        if ($repoDigests === null) {
            return false;
        }

        if ($image->isDigestPinned()) {
            return in_array($image->repository . '@' . $image->digest, $repoDigests, true);
        }

        return $repoDigests !== [];
    }

    /**
     * Decoded .RepoDigests for the image from the local daemon, or null when the image is not
     * present locally (inspect exits non-zero), the daemon is unreachable, or the field is null.
     *
     * @return list<mixed>|null
     */
    private function inspectRepoDigests(ImageReference $image): ?array
    {
        try {
            $argv = ['docker', 'image', 'inspect', '--format', '{{json .RepoDigests}}', $image->toString()];
            $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        } catch (\Throwable) {
            return null;
        }

        if (!$result->isSuccess()) {
            return null;
        }

        $decoded = json_decode(trim($result->stdout), true);
        if (!is_array($decoded)) {
            return null;
        }

        return array_values($decoded);
    }

    private function tryDaemonlessDigest(ImageReference $image): ?string
    {
        try {
            $argv = ['crane', 'digest', $image->toString()];
            $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
            if ($result->isSuccess()) {
                return $this->validateDigest(trim($result->stdout));
            }
        } catch (\Throwable) {
        }

        try {
            $argv = ['skopeo', 'inspect', '--raw', sprintf('docker://%s', $image->toString())];
            $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
            if ($result->isSuccess()) {
                $hash = hash('sha256', $result->stdout);

                return $this->validateDigest('sha256:' . $hash);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function extractDigestFromInspect(string $output, ImageReference $image): string
    {
        $hash = hash('sha256', $output);

        return $this->validateDigest('sha256:' . $hash);
    }

    private function validateDigest(string $digest): string
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new \RuntimeException(sprintf('Invalid digest format: %s', $digest));
        }

        return $digest;
    }
}
