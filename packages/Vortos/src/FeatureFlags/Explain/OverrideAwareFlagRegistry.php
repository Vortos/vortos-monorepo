<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Explain;

use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\FlagValueType;

/**
 * Decorator that injects per-request overrides from {@see FlagOverrideService} into
 * the evaluation result (Block 19). When the service has no overrides (the common path),
 * the delegate is called directly with zero overhead.
 */
final class OverrideAwareFlagRegistry implements FlagRegistryInterface
{
    public function __construct(
        private readonly FlagRegistryInterface $inner,
        private readonly FlagOverrideService $overrides,
    ) {}

    public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
    {
        $override = $this->overrides->getOverride($name);
        if ($override !== null) {
            return $override->asBool();
        }

        return $this->inner->isEnabled($name, $context);
    }

    public function variant(string $name, FlagContext $context = new FlagContext()): string
    {
        $override = $this->overrides->getOverride($name);
        if ($override !== null && $override->type === FlagValueType::String) {
            return $override->asString();
        }

        return $this->inner->variant($name, $context);
    }

    public function allForContext(FlagContext $context = new FlagContext()): array
    {
        $result = $this->inner->allForContext($context);

        // Apply overrides on top of the natural evaluation
        foreach ($this->overrides->auditLog() as $entry) {
            $flagName = $entry['flag'];
            $override = $this->overrides->getOverride($flagName);
            if ($override === null) {
                continue;
            }

            if ($override->asBool()) {
                if (!in_array($flagName, $result['flags'], true)) {
                    $result['flags'][] = $flagName;
                }
            } else {
                $result['flags'] = array_values(array_filter(
                    $result['flags'],
                    fn(string $f) => $f !== $flagName,
                ));
                unset($result['variants'][$flagName], $result['payloads'][$flagName]);
            }
        }

        return $result;
    }
}
