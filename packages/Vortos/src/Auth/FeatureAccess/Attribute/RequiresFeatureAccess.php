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
 * The route only declares *what* it requires. Whether a denial is 403 (plan does
 * not include it) or 402 (entitled but lapsed) is decided at request time by the
 * FeatureAccessPolicy, since only it knows the identity's billing state.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresFeatureAccess
{
    public readonly string $feature;

    public function __construct(
        string|\BackedEnum $feature,
    ) {
        $this->feature = $feature instanceof \BackedEnum ? $feature->value : $feature;
    }
}
