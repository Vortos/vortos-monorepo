<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

final class ObjectStoreLifecycleConfig
{
    private bool $enabled = true;
    private bool $manageTemporaryUploads = true;
    private string $ruleId = 'vortos-object-store-expire-temporary-uploads';
    private bool $requireConfirmation = true;
    private bool $roundUpMinimumLifecycleDay = false;

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

    /** @internal */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'manage_temporary_uploads' => $this->manageTemporaryUploads,
            'rule_id' => $this->ruleId,
            'require_confirmation' => $this->requireConfirmation,
            'round_up_minimum_lifecycle_day' => $this->roundUpMinimumLifecycleDay,
        ];
    }
}
