<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

/**
 * Marks a class as the default implementation for one or more interfaces.
 *
 * At container compile time, DefaultImplCompilerPass reads this attribute and
 * creates an alias from the specified interface(s) to the annotated class.
 * No manual alias registration in services.php is required.
 *
 * ## Single interface (inferred)
 *
 * When the class implements exactly one application interface, the interface
 * argument may be omitted — the compiler pass infers it automatically:
 *
 *   #[DefaultImpl]
 *   final class RedisCache implements CacheInterface {}
 *
 * ## Multiple interfaces (explicit)
 *
 * When the class implements multiple application interfaces, you MUST specify
 * which one to alias. Omitting the argument is a compile-time error:
 *
 *   #[DefaultImpl(CacheInterface::class)]
 *   final class RedisCache implements CacheInterface, TaggedCacheInterface {}
 *
 * ## Overriding in services.php
 *
 * The compiler pass creates the alias only if no alias for the interface exists
 * yet. Explicit aliases in services.php always take precedence.
 *
 * ## What counts as an "application interface"
 *
 * Interfaces from the PHP stdlib (Iterator, Countable, Stringable, etc.) and
 * from third-party vendor packages are excluded from auto-detection. Only
 * interfaces defined within the application's own namespaces are considered.
 * The root namespace prefix is resolved from composer.json autoload.psr-4.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DefaultImpl
{
    /**
     * @param class-string|null $interface The interface to alias this class to.
     *                                     Omit when the class implements exactly one app interface.
     */
    public function __construct(public readonly ?string $interface = null) {}
}
