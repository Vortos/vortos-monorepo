<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Verification;

use Vortos\Pipeline\Model\PinnedAction;

/**
 * Verifies that each pinned action's SHA actually exists in its upstream repository, and — as an
 * advisory — whether the claimed version comment still points at that SHA.
 *
 * The existence check is the fail-closed gate: a pin whose SHA cannot be resolved (B7) is a hard
 * failure. The version-drift note is informational only, because a moving major tag (e.g. `v4`)
 * legitimately advances past the immutable commit a pin was frozen to.
 */
final readonly class ActionPinVerifier
{
    public function __construct(
        private ActionRefResolverInterface $resolver,
        private ?ActionRuntimeResolverInterface $runtimeResolver = null,
    ) {}

    /**
     * @param list<PinnedAction> $actions
     * @return list<array{ref: string, sha: string, version: string, exists: bool, version_matches: ?bool, runtime: ?string, runtime_status: string, note: ?string}>
     */
    public function verify(array $actions): array
    {
        $results = [];

        foreach ($actions as $action) {
            $resolvedSha = $this->resolver->resolve($action->owner, $action->repo, $action->sha);
            $exists = $resolvedSha !== null && strtolower($resolvedSha) === strtolower($action->sha);

            $versionMatches = null;
            $note = null;
            if ($exists) {
                $versionSha = $this->resolver->resolve($action->owner, $action->repo, $action->versionComment);
                if ($versionSha !== null) {
                    $versionMatches = strtolower($versionSha) === strtolower($action->sha);
                    if ($versionMatches === false) {
                        $note = sprintf(
                            'Version "%s" now points to %s; the pin is frozen at %s (a newer release may exist).',
                            $action->versionComment,
                            substr($versionSha, 0, 12),
                            substr($action->sha, 0, 12),
                        );
                    }
                }
            } else {
                $note = sprintf('SHA %s does not exist in %s/%s.', $action->sha, $action->owner, $action->repo);
            }

            [$runtime, $status] = $this->resolveRuntime($action, $exists);

            $results[] = [
                'ref' => $action->toUsesString(),
                'sha' => $action->sha,
                'version' => $action->versionComment,
                'exists' => $exists,
                'version_matches' => $versionMatches,
                'runtime' => $runtime,
                'runtime_status' => $status->value,
                'note' => $note,
            ];
        }

        return $results;
    }

    /**
     * @return array{0: ?string, 1: ActionRuntimeStatus}
     */
    private function resolveRuntime(PinnedAction $action, bool $exists): array
    {
        if (!$exists || $this->runtimeResolver === null) {
            return [null, ActionRuntimeStatus::Unknown];
        }

        $runtime = $this->runtimeResolver->runtime($action->owner, $action->repo, $action->sha);

        return [$runtime, ActionRuntime::classify($runtime)];
    }

    /**
     * @param list<array{exists: bool, ...}> $results
     */
    public function allExist(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['exists'] !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fail-closed runtime gate: true unless some pin runs on a **removed** Node runtime (node16 and
     * older). Deprecated-but-still-executing runtimes (node20) are advisory and do not fail here.
     *
     * @param list<array{runtime_status?: string, ...}> $results
     */
    public function allRuntimesSupported(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['runtime_status'] ?? '') === ActionRuntimeStatus::Removed->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pins on a deprecated (not yet removed) runtime — advisory, surfaced but not fatal.
     *
     * @param list<array{runtime_status?: string, ...}> $results
     */
    public function hasDeprecatedRuntimes(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['runtime_status'] ?? '') === ActionRuntimeStatus::Deprecated->value) {
                return true;
            }
        }

        return false;
    }
}
