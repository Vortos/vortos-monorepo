<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Attribute;

/**
 * Enforces a quota on a controller or method.
 *
 * #[RequiresQuota('exports', cost: 1)]
 * #[RequiresQuota(Quota::Exports, cost: 1)]
 * public function exportRecord(): Response { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresQuota
{
    public readonly string $quota;

    public function __construct(
        string|\BackedEnum $quota,
        public readonly int $cost = 1,
    ) {
        $this->quota = $quota instanceof \BackedEnum ? $quota->value : $quota;
    }
}
