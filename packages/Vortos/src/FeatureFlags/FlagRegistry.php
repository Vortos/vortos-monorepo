<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\Authz\FlagAuthzGateInterface;
use Vortos\FeatureFlags\Resolution\EffectiveFlagResolverInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\FlagScopeContext;

final class FlagRegistry implements FlagRegistryInterface, ResetInterface
{
    /** Per-request in-memory cache — avoids repeated storage reads within one request. */
    private array $resolved = [];

    /**
     * @param ?EffectiveFlagResolverInterface $resolver override-aware resolution (tenant →
     *        env-scoped → global, Block 9/10). Null = read global storage directly (Phase A behaviour).
     * @param ?FlagAuthzGateInterface $authz deny-only authorization-scope gate (Block 9).
     *        Null = no gating.
     * @param ?FlagScopeContext $scope active env scope for cache-key namespacing (Block 10).
     *        Null = 'production' assumed (Phase A/B back-compat).
     */
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagEvaluator $evaluator,
        private readonly ?EffectiveFlagResolverInterface $resolver = null,
        private readonly ?FlagAuthzGateInterface $authz = null,
        private readonly ?FlagScopeContext $scope = null,
    ) {}

    /** The effective flag for this context (override-aware when a resolver is wired). */
    private function findFlag(string $name, FlagContext $context): ?FeatureFlag
    {
        return $this->resolver !== null
            ? $this->resolver->resolve($name, $context)
            : $this->storage->findByName($name);
    }

    public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
    {
        $key = ($this->scope?->environment() ?? FlagScopeContext::ENV_PRODUCTION) . '|' . $name . '|' . $context->cacheKey();

        if (!array_key_exists($key, $this->resolved)) {
            $flag = $this->findFlag($name, $context);
            $on   = $flag !== null && $this->evaluator->evaluate($flag, $context);
            if ($on && $this->authz !== null) {
                $on = $this->authz->allows($flag, $context);
            }
            $this->resolved[$key] = $on;
        }

        return $this->resolved[$key];
    }

    public function variant(string $name, FlagContext $context = new FlagContext()): string
    {
        $flag = $this->findFlag($name, $context);

        if ($flag === null) {
            return 'control';
        }

        // A flag vetoed by the authz gate yields the control variant.
        if ($this->authz !== null && !$this->authz->allows($flag, $context)) {
            return 'control';
        }

        return $this->evaluator->evaluateVariant($flag, $context);
    }

    public function reset(): void
    {
        $this->resolved = [];
    }

    /**
     * The full flag state for a context, shaped to the engine↔SDK wire contract
     * (see WIRE_CONTRACT.md): every field the `@vortos/flags` `FlagResponse` consumes.
     *
     * Only *resolved* values for this context are emitted — never the ruleset, user
     * lists, or unmatched payloads (PLATFORM §6, server-side evaluation only).
     *
     * @return array{
     *     flags: list<string>,
     *     variants: array<string,string>,
     *     payloads: array<string,mixed>,
     *     version: string
     * }
     */
    public function allForContext(FlagContext $context = new FlagContext()): array
    {
        $all      = $this->resolver !== null
            ? $this->resolver->resolveAll($context)
            : $this->storage->findAll();
        $flags    = [];
        $variants = [];
        $payloads = [];

        foreach ($all as $flag) {
            // The authz gate is deny-only: it can suppress a flag, never enable one.
            $allowed = $this->authz === null || $this->authz->allows($flag, $context);
            $on      = $allowed && $this->evaluator->evaluate($flag, $context);

            if ($on) {
                $flags[] = $flag->name;
            }

            if ($allowed && $flag->isVariant()) {
                $v = $this->evaluator->evaluateVariant($flag, $context);
                if ($v !== 'control') {
                    $variants[$flag->name] = $v;
                }
            }

            // Deliver the remote-config payload only for flags that are on for this context.
            $payload = $on ? $this->evaluator->evaluatePayload($flag, $context) : null;
            if ($payload !== null) {
                $payloads[$flag->name] = $payload;
            }
        }

        return [
            'flags'    => $flags,
            'variants' => $variants,
            'payloads' => $payloads,
            'version'  => $this->version($all),
        ];
    }

    /**
     * Deterministic config version: a stable hash over the full flag set. Identical
     * config yields an identical version on every node — the foundation for ETag/304
     * (Block 16) and SSE change detection (Block 27). Algorithm is pinned by
     * WIRE_CONTRACT.md and a fixture test; never change it silently.
     *
     * @param FeatureFlag[] $flags
     */
    private function version(array $flags): string
    {
        $canonical = array_map(fn(FeatureFlag $f) => $f->toArray(), $flags);
        usort($canonical, fn(array $a, array $b) => strcmp($a['name'], $b['name']));

        return 'v1:' . substr(
            hash('xxh3', json_encode($canonical, JSON_THROW_ON_ERROR)),
            0,
            16,
        );
    }
}
