<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Resolution;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * The base link of the resolution chain for per-environment flag state (Block 10).
 *
 * Replaces {@see GlobalFlagResolver} as the bottom of the chain. Composes each flag's
 * environment-invariant definition (from `FlagStorageInterface`) with its per-environment
 * mutable state (from `FlagEnvironmentStateStorageInterface`) into a single `FeatureFlag`
 * projection that the evaluator and registry consume unchanged.
 *
 * ## Performance (no N+1)
 *
 * Definitions and env states are bulk-loaded in exactly **two queries** per request —
 * regardless of how many flags exist. Results are memoized by (environment, project) key
 * for the lifetime of the request. Downstream links (tenant overrides) read the memoized
 * map. A query-count assertion in the test suite guards this invariant.
 *
 * ## Project isolation (Block 11)
 *
 * When a `ProjectContext` is wired in, only flags belonging to the active project are
 * returned. Filtering is done in PHP after the bulk load — the storage interface stays
 * unchanged and the result is still cache-compatible. The `default` project sees ALL flags
 * with no projectId row (legacy back-compat).
 *
 * ## Back-compatibility
 *
 * Phase A/B flags written before Block 10 have no env state row. For `production`
 * (the only env that existed before this block), the resolver falls back to the legacy
 * definition row's own state data (which contains the full flag state). For non-production
 * environments those flags are simply not visible — a safe default (no match → control
 * variant / flag's safe default value). On the first write to a legacy flag through
 * `FlagWriteService`, the env state row is created and back-compat mode is no longer needed.
 *
 * ## Security — cross-environment and cross-project isolation
 *
 * The active environment comes exclusively from {@see FlagScopeContext}, which is
 * populated server-side from the authenticated SDK key or a trusted gateway header —
 * never from `X-Vortos-Flag-Context` or any client input. The active project comes from
 * {@see ProjectContext} — likewise server-side only. A dev key cannot read prod state;
 * a prod key cannot pollute dev state; a project key cannot see another project's flags.
 */
final class EnvironmentScopedFlagResolver implements EffectiveFlagResolverInterface, ResetInterface
{
    /** @var array<string, FeatureFlag>|null memoized name→flag map */
    private ?array $memo    = null;
    private ?string $memoEnv = null;
    private ?string $memoProject = null;

    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagEnvironmentStateStorageInterface $envStates,
        private readonly FlagScopeContext $scope,
        private readonly ?ProjectContext $projectContext = null,
    ) {}

    public function resolve(string $name, FlagContext $context): ?FeatureFlag
    {
        return $this->loadMap()[$name] ?? null;
    }

    public function resolveAll(FlagContext $context): array
    {
        return array_values($this->loadMap());
    }

    /**
     * Materialize the full resolved flag map for the active environment and project.
     *
     * Two queries: (1) all definitions, (2) all env states for active env.
     * Everything else is O(n) PHP — no per-flag DB round-trip.
     *
     * @return array<string, FeatureFlag> keyed by flag name
     */
    private function loadMap(): array
    {
        $env     = $this->scope->environment();
        $project = $this->projectContext?->projectId() ?? ProjectContext::DEFAULT_PROJECT;

        if ($this->memoEnv === $env && $this->memoProject === $project && $this->memo !== null) {
            return $this->memo;
        }

        $definitions = $this->storage->findAll();               // query 1
        $states      = $this->envStates->findAllForEnv($env);  // query 2

        $map = [];
        foreach ($definitions as $definition) {
            // Project filter: skip flags that don't belong to the active project.
            if ($definition->projectId !== $project) {
                continue;
            }

            if (isset($states[$definition->id])) {
                // Env state exists → compose definition + state.
                $map[$definition->name] = FeatureFlag::compose($definition, $states[$definition->id]);
            } elseif ($env === FlagScopeContext::ENV_PRODUCTION) {
                // Back-compat: legacy flag (no env state row yet) in production — use the
                // definition row's own embedded state data. On the next write, a real env
                // state row is created and this path is no longer taken.
                $map[$definition->name] = $definition;
            }
            // else: flag not configured for this env → invisible (safe default / no match)
        }

        $this->memo        = $map;
        $this->memoEnv     = $env;
        $this->memoProject = $project;

        return $map;
    }

    public function reset(): void
    {
        $this->memo        = null;
        $this->memoEnv     = null;
        $this->memoProject = null;
    }
}
