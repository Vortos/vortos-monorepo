<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Attribute;

/**
 * Blocks access to a controller or method if the identity cannot access the feature.
 *
 * Accepts string or BackedEnum:
 *   #[RequiresFeatureAccess('api.bulk_export')]
 *   #[RequiresFeatureAccess(Feature::BulkExport)]
 *
 * Returns 403 when denied.
 * Returns 402 (Payment Required) when subscription is expired — set $paymentRequired: true
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresFeatureAccess
{
    public readonly string $feature;

    public function __construct(
        string|\BackedEnum $feature,
        public readonly bool $paymentRequired = false,
    ) {
        $this->feature = $feature instanceof \BackedEnum ? $feature->value : $feature;
    }
}
