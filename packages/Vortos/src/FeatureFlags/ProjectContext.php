<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The ambient project scope for the current request / unit of work (Block 11).
 *
 * Mirrors {@see FlagScopeContext} for the project dimension. One shared instance per
 * process; implements {@see ResetInterface} for worker-mode safety.
 *
 * ## How it gets populated
 *
 *   - HTTP: set from the authenticated SDK key after auth (Block 13).
 *   - CLI:  `--project` option on each command calls `withProject()`.
 *   - Workers: {@see self::runAs()} with the project from the message envelope.
 *
 * ## Security
 *
 * The project id is as privileged as the tenant id — it must never come from a
 * client-controlled header. An SDK key physically cannot see another project's flags.
 */
final class ProjectContext implements ResetInterface
{
    public const DEFAULT_PROJECT = Project::DEFAULT_SLUG;

    private string $projectId = self::DEFAULT_PROJECT;

    public function projectId(): string
    {
        return $this->projectId;
    }

    /**
     * @throws \InvalidArgumentException if the project id is blank or > 191 chars
     */
    public function withProject(string $projectId): void
    {
        $projectId = trim($projectId);

        if ($projectId === '' || strlen($projectId) > 191) {
            throw new \InvalidArgumentException(
                sprintf('Invalid project id "%s" — must be 1–191 non-blank characters.', $projectId),
            );
        }

        $this->projectId = $projectId;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runAs(string $projectId, callable $work): mixed
    {
        $previous = $this->projectId;
        $this->withProject($projectId);

        try {
            return $work();
        } finally {
            $this->projectId = $previous;
        }
    }

    public function reset(): void
    {
        $this->projectId = self::DEFAULT_PROJECT;
    }
}
