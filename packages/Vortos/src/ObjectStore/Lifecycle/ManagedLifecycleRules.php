<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

/**
 * The set of lifecycle rules the application owns in a bucket.
 *
 * Ownership is by rule-ID namespace, not by "whatever is declared right now". A rule deleted from
 * config must also leave the bucket, and the only way to recognise it afterwards is that its ID still
 * carries the namespace. Rules outside the namespace — created in the provider console, or the
 * provider's own defaults such as R2's multipart-abort rule — are never touched.
 */
final class ManagedLifecycleRules
{
    /** S3 and R2 both reject a lifecycle configuration with more than 1000 rules. */
    public const MAX_RULES_PER_BUCKET = 1000;

    /** @var list<LifecycleRule> */
    private readonly array $rules;

    /** @param list<LifecycleRule> $rules */
    public function __construct(
        private readonly string $ruleIdPrefix,
        array $rules,
    ) {
        if (trim($ruleIdPrefix) === '') {
            throw new ObjectStoreConfigurationException('Managed lifecycle rule ID prefix cannot be empty; every rule in the bucket would count as managed and be removed when undeclared.');
        }

        $ids = [];
        foreach ($rules as $rule) {
            if (!str_starts_with($rule->id(), $ruleIdPrefix)) {
                throw new ObjectStoreConfigurationException(sprintf(
                    'Lifecycle rule "%s" must start with the managed prefix "%s", or removing it from config would leave it in the bucket forever.',
                    $rule->id(),
                    $ruleIdPrefix,
                ));
            }

            if (isset($ids[$rule->id()])) {
                throw new ObjectStoreConfigurationException(sprintf('Lifecycle rule "%s" is declared more than once.', $rule->id()));
            }
            $ids[$rule->id()] = true;
        }

        if (count($rules) > self::MAX_RULES_PER_BUCKET) {
            throw new ObjectStoreConfigurationException(sprintf('At most %d lifecycle rules can be declared.', self::MAX_RULES_PER_BUCKET));
        }

        $this->rules = $rules;
    }

    /** @return list<LifecycleRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function owns(string $ruleId): bool
    {
        return str_starts_with($ruleId, $this->ruleIdPrefix);
    }

    public function rule(string $ruleId): ?LifecycleRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->id() === $ruleId) {
                return $rule;
            }
        }

        return null;
    }

    public function hasTransitions(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->transitions() !== []) {
                return true;
            }
        }

        return false;
    }
}
