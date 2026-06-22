<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Validation;

use Vortos\FeatureFlags\Exception\InvalidFlagException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Validates a flag at write time so the evaluator can stay total and loop-free:
 *  - prerequisite cycles (A→B→A, self-deps) are rejected;
 *  - permission flags may not target attacker-controlled (untrusted) context;
 *  - variant weights may not exceed 100%.
 */
final class FlagValidator
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
    ) {}

    public function validate(FeatureFlag $flag): void
    {
        $this->validateVariants($flag);
        $this->validatePermissionTargeting($flag);
        $this->assertNoPrerequisiteCycle($flag);
    }

    private function validateVariants(FeatureFlag $flag): void
    {
        if ($flag->variants === null) {
            return;
        }

        $sum = array_sum($flag->variants);
        if ($sum > 100) {
            throw new InvalidFlagException(
                sprintf('Variant weights for "%s" sum to %d%% (max 100%%).', $flag->name, $sum),
            );
        }
    }

    private function validatePermissionTargeting(FeatureFlag $flag): void
    {
        if (!$flag->kind->requiresTrustedTargeting()) {
            return;
        }

        // A permission gate must not bucket on client-owned dimensions.
        if (in_array($flag->bucketBy, [FeatureFlag::BUCKET_BY_DEVICE, FeatureFlag::BUCKET_BY_SESSION], true)) {
            throw new InvalidFlagException(
                sprintf('Permission flag "%s" must not bucket on the untrusted "%s" dimension.', $flag->name, $flag->bucketBy),
            );
        }

        foreach ($flag->rules as $rule) {
            $this->assertRuleTrusted($rule, $flag->name);
        }
    }

    private function assertRuleTrusted(FlagRule $rule, string $flagName): void
    {
        if ($rule->type === FlagRule::TYPE_SEGMENT) {
            // A segment can contain arbitrary (untrusted) rules we can't statically vet.
            throw new InvalidFlagException(
                sprintf('Permission flag "%s" must not reference segments (untrusted targeting risk).', $flagName),
            );
        }

        if ($rule->type === FlagRule::TYPE_ATTRIBUTE && $rule->zone !== FlagRule::ZONE_TRUSTED) {
            throw new InvalidFlagException(
                sprintf('Permission flag "%s" attribute rules must use the trusted zone (got "%s").', $flagName, $rule->zone),
            );
        }

        foreach ($rule->children as $child) {
            $this->assertRuleTrusted($child, $flagName);
        }
    }

    private function assertNoPrerequisiteCycle(FeatureFlag $flag): void
    {
        $this->walk($flag->name, $flag->prerequisites, [$flag->name]);
    }

    /**
     * DFS over the prerequisite graph. The flag being saved uses its in-memory
     * prerequisites (its stored version may be stale); dependencies are loaded from
     * storage. Revisiting any node already on the current path is a cycle.
     *
     * @param \Vortos\FeatureFlags\Prerequisite[] $prerequisites
     * @param string[] $path
     */
    private function walk(string $name, array $prerequisites, array $path): void
    {
        foreach ($prerequisites as $prerequisite) {
            $dependency = $prerequisite->flag;

            if (in_array($dependency, $path, true)) {
                throw new InvalidFlagException(
                    sprintf('Prerequisite cycle detected: %s → %s.', implode(' → ', $path), $dependency),
                );
            }

            $target = $this->storage->findByName($dependency);
            if ($target === null || $target->prerequisites === []) {
                continue; // missing or leaf — no further edges to follow
            }

            $this->walk($dependency, $target->prerequisites, [...$path, $dependency]);
        }
    }
}
