<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

/**
 * Marks a class as the overriding implementation for an interface.
 *
 * Unlike #[DefaultImpl], this attribute unconditionally replaces any existing
 * alias or definition registered by framework extensions. Use it when app code
 * needs to swap out a framework-provided binding without touching services.php.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class OverrideImpl
{
    /**
     * @param class-string|null $interface
     *   The interface to alias this class to.
     *   Omit when the class implements exactly one app interface.
     */
    public function __construct(public readonly ?string $interface = null) {}
}
