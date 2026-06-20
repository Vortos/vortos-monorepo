<?php

declare(strict_types=1);

namespace Vortos\Authorization\Scope;

/**
 * Classifies a permission's scope segment into a {@see ScopeEnforcement} kind.
 *
 * Safe-by-default: any scope neither configured by the app nor defaulted by the
 * framework is treated as {@see ScopeEnforcement::Ownership} — i.e. it fails closed
 * (a policy becomes mandatory) rather than being silently allowed.
 *
 * The framework presumes to know only the universal scope names. Everything else —
 * `org`, `team`, `federation`, `tenant`, `region`, … — is an app concept the app
 * must classify; until it does, those scopes fail closed.
 */
final class ScopeEnforcementClassifier
{
    /** @var array<string, ScopeEnforcement> The only scope names the framework defaults. */
    private const FRAMEWORK_DEFAULTS = [
        'any' => ScopeEnforcement::SelfSufficient,
        'global' => ScopeEnforcement::SelfSufficient,
        'own' => ScopeEnforcement::Ownership,
    ];

    /** @var array<string, ScopeEnforcement> */
    private array $map;

    /**
     * @param array<string, ScopeEnforcement|string> $appMap App scope name => enforcement kind.
     *        Values may be ScopeEnforcement instances (programmatic) or their string values
     *        (config). App entries override framework defaults for the same name.
     */
    public function __construct(array $appMap = [])
    {
        $normalized = [];

        foreach ($appMap as $scope => $kind) {
            $normalized[$scope] = $kind instanceof ScopeEnforcement
                ? $kind
                : ScopeEnforcement::from($kind);
        }

        // App entries win on key collision; framework defaults fill the rest.
        $this->map = $normalized + self::FRAMEWORK_DEFAULTS;
    }

    public function classify(string $scope): ScopeEnforcement
    {
        return $this->map[$scope] ?? ScopeEnforcement::Ownership;
    }
}
