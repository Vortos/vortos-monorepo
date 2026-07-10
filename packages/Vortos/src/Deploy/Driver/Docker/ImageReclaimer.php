<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Docker;

use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\RemoteCommand;
use Vortos\Deploy\Execution\SshTransportInterface;
use Vortos\Deploy\Plan\ImagePrunePolicy;

/**
 * Reclaims superseded release images + build cache on the deploy target, reclaiming disk
 * without ever endangering a healthy release or a rollback target.
 *
 * The keep-set is REFERENCE-COUNTED, not recency-guessed. An image of the deployed repository
 * is retained iff it satisfies ANY of:
 *
 *   1. it is referenced by an existing container (running OR exited) — docker refuses to remove
 *      these anyway; this pins the active color and any standby the runtime still holds;
 *   2. its registry digest is in reclaim()'s protectedDigests — the release-authoritative set
 *      (current live release + previous-for-rollback), so the intended rollback target survives
 *      even after its standby container has been composed down;
 *   3. it is within the {@see ImagePrunePolicy::$keep} most-recent images (a conservative recency
 *      floor / backstop, kept for backward-compatible behaviour).
 *
 * Everything else of the repository is removed INDIVIDUALLY with docker image rm and NO force flag,
 * so an image unexpectedly still referenced by a running container makes the removal fail — that is
 * caught and the image is left in place (double safety for the active color). A blanket
 * all-unused prune (docker image prune -a / docker system prune) is never used.
 *
 * This is the SINGLE source of truth for reclaim, shared by:
 *   - the ssh-compose deploy target, which reclaims after EVERY deploy attempt (success AND
 *     failure — a failed deploy's orphaned pull is reclaimed the same way), and
 *   - the scheduled image-gc safety-net, which runs the identical pass on a cadence so disk is
 *     bounded even when no deploy has run (or a deploy process was killed before it could reclaim).
 *
 * Every command is isolated: any failure is recorded as a note and skipped, and reclaim() never
 * throws. It is idempotent — running it twice removes nothing the first pass already removed.
 */
final class ImageReclaimer
{
    public function __construct(
        private readonly CommandRunnerInterface $localRunner,
        private readonly ?SshTransportInterface $sshTransport = null,
    ) {}

    /**
     * @param list<string> $protectedDigests registry digests ("sha256:<64 hex>") that must never be
     *                                        removed regardless of recency — the current live release
     *                                        and the previous-for-rollback digest
     */
    public function reclaim(string $repository, ImagePrunePolicy $policy, array $protectedDigests = []): ReclaimReport
    {
        if (!$policy->enabled) {
            return ReclaimReport::disabled();
        }

        $notes = [];
        $removed = 0;
        $kept = 0;

        try {
            $result = $this->reclaimSupersededImages($repository, $policy->keep, $protectedDigests);
            $removed = $result['removed'];
            $kept = $result['kept'];
            $notes[] = sprintf('removed %d superseded image(s), kept %d', $result['removed'], $result['kept']);
        } catch (\Throwable $e) {
            $notes[] = 'superseded-image prune skipped: ' . $e->getMessage();
        }

        try {
            $this->run(['docker', 'image', 'prune', '-f']);
            $notes[] = 'dangling layers pruned';
        } catch (\Throwable $e) {
            $notes[] = 'dangling prune skipped: ' . $e->getMessage();
        }

        try {
            $this->run(['docker', 'builder', 'prune', '-f', '--filter', 'until=' . $policy->builderCacheMaxAge]);
            $notes[] = 'build cache pruned (until=' . $policy->builderCacheMaxAge . ')';
        } catch (\Throwable $e) {
            $notes[] = 'build-cache prune skipped: ' . $e->getMessage();
        }

        return new ReclaimReport(enabled: true, removed: $removed, kept: $kept, notes: $notes);
    }

    /**
     * @param list<string> $protectedDigests
     *
     * @return array{removed: int, kept: int}
     */
    private function reclaimSupersededImages(string $repository, int $keep, array $protectedDigests): array
    {
        // One row per local image of the repository, newest-first (docker default), carrying the
        // image ID and its registry digest so we can reference-count against releases + containers.
        $result = $this->run(['docker', 'images', $repository, '--no-trunc', '--format', '{{.ID}}|{{.Digest}}']);

        /** @var list<string> $orderedIds distinct image IDs, newest-first */
        $orderedIds = [];
        /** @var array<string, string> $digestToId registry digest => image ID */
        $digestToId = [];

        foreach (preg_split('/\r?\n/', trim($result->stdout)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$id, $digest] = array_pad(explode('|', $line, 2), 2, '');
            $id = trim($id);
            $digest = trim($digest);

            if ($id === '') {
                continue;
            }

            if (!in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }

            // A "none" digest marks a locally-built image with no registry provenance — not
            // release-matchable, so it is left to the recency/container-ref checks.
            if ($digest !== '' && $digest !== '<none>' && !isset($digestToId[$digest])) {
                $digestToId[$digest] = $id;
            }
        }

        if ($orderedIds === []) {
            return ['removed' => 0, 'kept' => 0];
        }

        $keepIds = $this->computeKeepSet($orderedIds, $digestToId, $keep, $protectedDigests);

        $removed = 0;
        foreach ($orderedIds as $id) {
            if (isset($keepIds[$id])) {
                continue;
            }

            try {
                // No force flag: an image still referenced by a running container must NOT be
                // force-removed — docker rejects the removal and we leave it in place.
                $rm = $this->run(['docker', 'image', 'rm', $id]);
                if ($rm->isSuccess()) {
                    $removed++;
                }
            } catch (\Throwable) {
                // In-use / already-gone — leave it, continue.
            }
        }

        return ['removed' => $removed, 'kept' => count($keepIds)];
    }

    /**
     * @param list<string>          $orderedIds  distinct image IDs, newest-first
     * @param array<string, string> $digestToId  registry digest => image ID
     * @param list<string>          $protectedDigests
     *
     * @return array<string, true> keep-set as an ID => true lookup
     */
    private function computeKeepSet(array $orderedIds, array $digestToId, int $keep, array $protectedDigests): array
    {
        $keepIds = [];

        // (3) Recency floor — the newest $keep images are always retained.
        foreach (array_slice($orderedIds, 0, max(0, $keep)) as $id) {
            $keepIds[$id] = true;
        }

        // (2) Release-authoritative digests — current live release + previous-for-rollback.
        foreach ($protectedDigests as $digest) {
            if ($digest !== '' && isset($digestToId[$digest])) {
                $keepIds[$digestToId[$digest]] = true;
            }
        }

        // (1) Container-referenced images — anything a container (running or exited) still holds.
        foreach ($this->containerReferencedImageIds() as $id) {
            // Only relevant when it is one of THIS repository's images; docker would refuse the rm
            // anyway, but keeping the set precise avoids logging spurious "in-use" skips.
            if (in_array($id, $orderedIds, true)) {
                $keepIds[$id] = true;
            }
        }

        return $keepIds;
    }

    /**
     * The image IDs every existing container references, resolved via inspect so we get the
     * concrete image ID ("sha256:…") rather than the possibly-tagged create reference. Best-effort:
     * any failure yields an empty set (the release stays protected by digest + recency).
     *
     * @return list<string>
     */
    private function containerReferencedImageIds(): array
    {
        try {
            $ps = $this->run(['docker', 'ps', '-aq', '--no-trunc']);
        } catch (\Throwable) {
            return [];
        }

        $containerIds = [];
        foreach (preg_split('/\r?\n/', trim($ps->stdout)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $containerIds[] = $line;
            }
        }

        if ($containerIds === []) {
            return [];
        }

        try {
            $inspect = $this->run(array_merge(['docker', 'inspect', '--format', '{{.Image}}'], $containerIds));
        } catch (\Throwable) {
            return [];
        }

        $imageIds = [];
        foreach (preg_split('/\r?\n/', trim($inspect->stdout)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $imageIds, true)) {
                $imageIds[] = $line;
            }
        }

        return $imageIds;
    }

    /** @param list<string> $argv */
    private function run(array $argv): CommandResult
    {
        if ($this->sshTransport !== null) {
            return $this->sshTransport->run(new RemoteCommand($argv));
        }

        return $this->localRunner->run($argv);
    }
}
