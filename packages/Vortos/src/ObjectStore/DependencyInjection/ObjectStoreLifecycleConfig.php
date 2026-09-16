<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

use Vortos\ObjectStore\Lifecycle\LifecycleRule;

final class ObjectStoreLifecycleConfig
{
    private bool $enabled = true;
    private bool $manageTemporaryUploads = true;
    private string $ruleId = 'vortos-object-store-expire-temporary-uploads';
    private bool $requireConfirmation = true;
    private bool $roundUpMinimumLifecycleDay = false;
    private string $managedRuleIdPrefix = 'vortos-';

    /** @var list<array<string, mixed>> */
    private array $rules = [];

    public function enabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function manageTemporaryUploads(bool $enabled): static
    {
        $this->manageTemporaryUploads = $enabled;
        return $this;
    }

    public function ruleId(string $ruleId): static
    {
        $this->ruleId = trim($ruleId);
        return $this;
    }

    public function requireConfirmation(bool $required): static
    {
        $this->requireConfirmation = $required;
        return $this;
    }

    public function roundUpMinimumLifecycleDay(bool $enabled): static
    {
        $this->roundUpMinimumLifecycleDay = $enabled;
        return $this;
    }

    /**
     * The rule-ID namespace this application owns in the bucket. Managed rules that are no longer
     * declared are removed on the next apply; rules outside the namespace are never touched.
     */
    public function managedRuleIdPrefix(string $prefix): static
    {
        $this->managedRuleIdPrefix = $prefix;
        return $this;
    }

    /**
     * Declares a lifecycle rule for the bucket, e.g.
     * `->rule(LifecycleRule::transitionAfter('vortos-app-uploads-ia', 'uploads/', 90, ObjectStorageClass::InfrequentAccess))`.
     *
     * The rule is validated here, when config loads, so a bad declaration fails the container build
     * rather than the first `lifecycle plan` in production.
     */
    public function rule(LifecycleRule $rule): static
    {
        $this->rules[] = $rule->toConfigArray();
        return $this;
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'manage_temporary_uploads' => $this->manageTemporaryUploads,
            'rule_id' => $this->ruleId,
            'require_confirmation' => $this->requireConfirmation,
            'round_up_minimum_lifecycle_day' => $this->roundUpMinimumLifecycleDay,
            'managed_rule_id_prefix' => $this->managedRuleIdPrefix,
            'rules' => $this->rules,
        ];
    }
}
