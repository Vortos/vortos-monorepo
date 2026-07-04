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
    public function __construct(private ActionRefResolverInterface $resolver) {}

    /**
     * @param list<PinnedAction> $actions
     * @return list<array{ref: string, sha: string, version: string, exists: bool, version_matches: ?bool, note: ?string}>
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

            $results[] = [
                'ref' => $action->toUsesString(),
                'sha' => $action->sha,
                'version' => $action->versionComment,
                'exists' => $exists,
                'version_matches' => $versionMatches,
                'note' => $note,
            ];
        }

        return $results;
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
}
